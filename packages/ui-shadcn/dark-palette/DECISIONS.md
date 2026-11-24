# Decisions

Calls made while building the Neutral dark palette, with the reason for each. The numbers behind them are in `report.md`.

## Method

1. **Scales, not per-token values.** The designer asked for "a similar scale". Every primitive scale newspack-ui aliases (neutral, primary, success, error, warning, overlay) gets a dark scale with the same step names. Semantic tokens keep their light aliases, so dark mode is the primitives redefined.
2. **Dark step N = light step N's signed Lc, reversed.** Light is measured on white, dark on neutral-900 (#1e1e1e), as the designer confirmed. Anything more than 1 Lc off is logged as an exception. Only the pinned neutral-90 is.
3. **Clipped steps mirror oklab L.** APCA reports Lc 0 for neutral-5, neutral-10 and the status step-0 tints on white, so it cannot place them. They take dark L = 0.235 + (1 − light L), as the coordinator specified. They also read Lc 0 on #1e1e1e, so the rule still holds.
4. **Pins.** neutral-0 = #1e1e1e (dark base) and neutral-90 = #ffffff ("my text must be white"). Every on-fill token (button ink, snackbar, check glyph, text on brand) aliases one of these two, so each becomes the exact swap with no extra override. The destructive ink is the exception the designer asked for (see 15).
5. **Hue kept, chroma kept, with a floor.** A dark step keeps its light oklch chroma and loses chroma only to stay in sRGB, so light dark-mode steps never glow more than their light versions. Pure relative-chroma matching was tried: it made success-50/60 neon (c 0.26 to 0.28) and error-5 vivid, so I dropped it. The plain rule left error-5 and error-0 a muddy mauve gray, so chroma is floored at half the light step's relative saturation. That lifts only the mid-lightness tints and borders. After the low-tint rule (26), it mainly keeps the mirrored tints from going gray.
6. **Snap tolerance.** A solved value snaps to an existing newspack-colors value of the same scale if it lands within 0.5 Lc of the target and 0.02 oklab distance. neutral-30 (#5d5d5d), neutral-80 (#f0f0f0) and neutral-100 (#fff) snapped.
7. **Aliases are re-declared in `dark.css`.** A custom property resolves `var()` where it is declared, so aliases resolved on `:root` would keep the light values under `.newspack-ui[data-color-scheme]`. Re-declaring them unchanged makes them re-resolve against the dark primitives.
8. **The dark root paints its own background and text.** `dark.css` sets `background-color: var(--newspack-ui-color-body-bg)` and `color` on the opted-in element. Without that, a dark `.newspack-ui` inside a light theme page would draw white text on white.

## Overlay and scrim

9. **The overlay scale is placed by its effect.** Black at an alpha has no Lc of its own. Each step is solved so that page text under it keeps the light Lc, reversed. Steps 0 to 70 land within 0.02 alpha of the light values (step 0 slightly higher, 30 to 70 slightly lower); steps 80 to 100 clip in both modes and keep their light alphas.
10. **No heavier scrim.** The brief expected a higher alpha. By this measure 48.4% black pushes a dark page back as far as 50% pushes a light one, and a heavier scrim would not separate two near-blacks any better. The dialog is separated by its raised surface and the hairline edge instead.

## Surfaces and elevation

11. **Raised surfaces come from the mirrored tints.** Dropdowns and the select menu (elevation 2) use dark neutral-5 (#242424). Modals and step sheets (elevation 4) use dark neutral-10 (#292929). That keeps them on the scale. Cards stay on the page surface, as in light. The snackbar (elevation 3) is the inverse surface. The surface-derived tokens are re-declared inside those scopes, so notices, fills and fields inside a modal step up with it (checked in the browser).
12. **Shadows follow the existing dark-base precedent.** I used black ink, strength 2.5 and a 14% contrast hairline, the values in `Newspack_UI_Palette::ELEVATION_DARK`, so the two dark paths agree. The light formula (contrast mixed toward black) would give a gray glow in dark.

## Overrides

Only twelve tokens get their own dark value.

13. **`elevation-ink`, `-strength`, `-edge`**: see 12.
14. **`field-caret`**: set to the existing light caret, because the dark caret SVG has a baked #1e1e1e fill.
15. **Destructive fill (designer call after review):** `error-fill`, `-fill-hover` and `-fill-pressed` become a saturated red, and `on-error` points at neutral-90 (white). The swapped pastel salmon with dark text read as pink, not as danger. I solved each fill by APCA for white text at light's Lc, with the oklch hue held. Each landed on the red light already uses, so light and dark share #d63638 / #b32d2e / #8a2424: white text reads Lc −76.8, −85.2 and −93.8. Hover and pressed get darker and stay red. The destructive ghost and outline buttons keep the swapped light red text, as the designer asked.
15a. **`error-tint-pressed` override removed.** It existed because error-text on the old error-5 read Lc −59.6. With error-5 now a deep tint (26), the pair reads −74.9 from the scale alone. Light still misses this floor (Lc 58.0); I flagged it and did not fix it, since light is out of scope.
16. **`disabled-ink`**: the 45% mix reads Lc −29 in dark, so disabled labels nearly vanish (seen in the preview). Disabled text has no floor, but parity with light matters, so the share is solved to 65% for light's Lc 52.7, reversed.

## Kept as is

17. **Hover and pressed shares stay at 85% and 75%.** Oklab mixing moves lightness by the same amount in both modes, so the state change looks the same size. Text on the primary hover and pressed fills reads Lc 81 and 68, against 100 and 93 in light. Matching exactly would need 98% and 94%, which would make hover nearly invisible on a white button.
18. **The outline edge (60%) and focus halo (30%) stay as is.** Their Lc drops in dark, but the edge stays at −52 (floor 25) and the halo only backs up a full-strength ring.
19. **Superseded by 15.** At first the destructive button became a light red fill with #1e1e1e text, the exact swap. The designer replaced that with the saturated red and white text.
20. **Body text is pure white (Lc −106).** It was pinned by the designer. The report notes that APCA guidance puts comfortable dark body text nearer Lc 90.

## Charts

21. **Charts are the one departure from the exact reversed Lc.** Reversed exactly, navy series 0 became near-white with no blue left, and could not be told from series 7. All eight series keep their light Lc gaps but shift by 19.5, so the strongest lands at Lc −80. That is about the last level where the dark blue scale still reads blue. The lowest lands at −42.6, above the mark floor of 25. No series is used as text in Insights (only strokes, fills and swatches), so the text floor of 60 is informational. Where a dark scale step of the same family is within 1 Lc, the series uses it (primary-30, neutral-50, neutral-60).
21a. **Superseded by 21b.** The oranges briefly had their own saturation-band rule (#ff6d40, #f65000, #cd4a00).
21b. **Series 0 to 5 now come straight from newspack-colors (designer call).** The values are read from `colors.module.scss`, not hardcoded:
    - Blues: primary-100 #9fb6dd (Lc −60.4), primary-200 #809ed2 (−47.5), primary-300 #6086c7 (−35.7).
    - Ambers: quaternary-500 #ffa300 (−62.2), quaternary-600 #dc8c00 (−48.2), quaternary-700 #c77e00 (−40.2).

    Every series is then an existing token, the blue and amber tiers carry equal weight (which fixes the pale-blue imbalance), and blue against amber stays color-blind robust. Series 6 and 7 keep the dark neutral grays. All series clear Lc 25. They are written as hex in `dark.css`, because the dark scale redefines the matching `--newspack-ui-color-primary-*` tokens.

    Series 0 and 3 are about the same lightness (Lc 0 between them, oklab 0.233) and separate by hue, as navy and rust do in light (0.30). The tightest neighbors are 4 and 5 (oklab 0.053), then 0 and 1 and 1 and 2 (0.08). These are flagged, not changed.

## Scope

22. **No info scale exists.** newspack-colors has none and newspack-ui has no info role, so the default neutral notice plays that part.
23. **No dark scales for secondary, tertiary or quaternary.** newspack-ui does not alias them.
24. **A theme's own brand color is not adapted.** If a theme sets `--newspack-theme-color-primary`, `--newspack-ui-color-primary` keeps that light-mode value. Neutral does not use it in components.
25. **The preview uses real newspack-ui CSS.** Hover, pressed and focus are forced by `preview-states.css`, which the script generates by cloning the real `:hover`, `:active` and `:focus-visible` rules onto classes. The screenshot was taken through a localhost server, because playwright-cli blocks `file:` URLs.

## Low tints (designer call after review)

26. **The mirrored lightness rule extends to hue-scale steps under Lc 30.** That covers primary-0, primary-5, success-5, error-5 and warning-5. APCA's curve is steep near a dark base, so reversing Lc 12 to 26 put these on heavy mid-tones (#5d7296, #8f4f57, #406a48, #5d4f15) where light has pale tints. Now they mirror their oklab L distance from white above #1e1e1e: #36445b, #532b30, #1c4526, #3b3000, and #263042 for primary-0.
27. **Why 30.** In newspack-ui, every hue step under it is a tint or a decorative border: error-border, error-tint-pressed, success-border and warning-border. The first steps above it (primary-10 at Lc 40, warning-30 at 43) are mark-weight colors, so they keep the reversed rule.
28. **Neutral is excluded.** neutral-20 to -40 (Lc 16 to 27) are the border and disabled-edge grays, which need their reversed weight to stay visible on the dark page.
29. **What moved in the pairs.** From the scale alone, the notice borders dropped to Lc 0 on their tints (fixed in 30). The pressed destructive ghost goes from −59.6 to −74.9. No pair fails.
30. **Colored notice borders get their own dark values (designer call).** I measured each light border against its light tint: error 12.3, success 13.9, warning 8.5. In dark, each border takes that Lc reversed against its own dark tint, floored at Lc 15, so all three land at the floor: visible, but not a mark-level edge. The hue of the light border step is held. Values: error-border #84484f (Lc −15.1 on #371e21), success-border #396341 (−15.2 on #1e2720), warning-border #66581f (−15.1 on #262316). They are explicit overrides; the tints and the -5 scale steps are unchanged. Nothing else reads these tokens, since badges and boxes have no border.
