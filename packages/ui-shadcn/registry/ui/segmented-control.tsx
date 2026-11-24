"use client"

import * as React from "react"
import { cn } from "@/lib/utils"
import { ToggleGroup as ToggleGroupPrimitive } from "radix-ui"

const SegmentedControlContext = React.createContext<(value: string) => void>(
  () => {}
)

type SegmentedControlProps = Omit<
  React.ComponentProps<typeof ToggleGroupPrimitive.Root>,
  "type" | "value" | "defaultValue" | "onValueChange" | "multiple"
> & {
  value?: string
  defaultValue?: string
  onValueChange?: (value: string) => void
  size?: "default" | "sm"
}

/**
 * Picks one value, or switches the view of the same content (frequency,
 * light or dark, list or grid). One item is always selected. To switch
 * between different panels of content use Tabs.
 */
function SegmentedControl({
  className,
  size = "default",
  value,
  defaultValue,
  onValueChange,
  ...props
}: SegmentedControlProps) {
  const rootRef = React.useRef<HTMLDivElement>(null)
  const [internal, setInternal] = React.useState(defaultValue ?? "")
  const current = value ?? internal

  const place = React.useCallback((animate: boolean) => {
    const root = rootRef.current
    if (!root) return
    const selected = root.querySelector<HTMLElement>('[data-state="on"]')
    if (!selected || !selected.offsetWidth) {
      root.removeAttribute("data-thumb")
      return
    }
    const measuring = !animate || !root.hasAttribute("data-thumb")
    if (measuring) root.setAttribute("data-measuring", "")
    root.style.setProperty("--seg-x", `${selected.offsetLeft}px`)
    root.style.setProperty("--seg-y", `${selected.offsetTop}px`)
    root.style.setProperty("--seg-w", `${selected.offsetWidth}px`)
    root.style.setProperty("--seg-h", `${selected.offsetHeight}px`)
    root.setAttribute("data-thumb", "")
    if (measuring) {
      void root.offsetWidth
      root.removeAttribute("data-measuring")
    }
  }, [])

  React.useLayoutEffect(() => {
    place(true)
  }, [current, size, place])

  React.useEffect(() => {
    const root = rootRef.current
    if (!root || typeof ResizeObserver === "undefined") return
    const observer = new ResizeObserver(() => place(false))
    observer.observe(root)
    return () => observer.disconnect()
  }, [place])

  const select = React.useCallback(
    (next: string) => {
      if (!next) return
      setInternal(next)
      onValueChange?.(next)
    },
    [onValueChange]
  )

  return (
    <SegmentedControlContext.Provider value={select}>
    <ToggleGroupPrimitive.Root
      ref={rootRef}
      type="single"
      data-slot="segmented-control"
      data-size={size}
      value={current}
      onValueChange={select}
      className={cn(
        "group/segmented relative isolate inline-flex w-fit max-w-full flex-wrap justify-center gap-(--newspack-ui-spacer-half) rounded-(--newspack-ui-radius-surface) bg-(--newspack-ui-color-neutral-fill) p-(--newspack-ui-spacer-half)",
        "[--seg-gap:var(--newspack-ui-spacer-1)] [--seg-height:var(--newspack-ui-spacer-8)] [--seg-py:var(--newspack-ui-spacer-1)] [--seg-px:calc(var(--newspack-ui-spacer-1)*2.25)] [--seg-fs:var(--newspack-ui-font-size-body)] [--seg-lh:var(--newspack-ui-line-height-body)]",
        "data-[size=sm]:[--seg-gap:calc(var(--newspack-ui-spacer-1)*0.75)] data-[size=sm]:[--seg-height:var(--newspack-ui-spacer-7)] data-[size=sm]:[--seg-px:calc(var(--newspack-ui-spacer-1)*1.75)] data-[size=sm]:[--seg-fs:var(--newspack-ui-font-size-small)] data-[size=sm]:[--seg-lh:var(--newspack-ui-line-height-small)]",
        "before:pointer-events-none before:absolute before:top-0 before:left-0 before:h-(--seg-h,0px) before:w-(--seg-w,0px) before:rounded-(--newspack-ui-radius-segment-inner) before:bg-(--newspack-ui-color-surface) before:shadow-[inset_0_0_0_1px_var(--newspack-ui-color-border-outline)] before:transition-[transform,width,height] before:duration-(--newspack-ui-duration-indicator) before:ease-(--newspack-ui-ease-out) before:content-[''] before:[transform:translate(var(--seg-x,0px),var(--seg-y,0px))]",
        "motion-reduce:before:transition-none data-[measuring]:before:transition-none forced-colors:before:hidden",
        className
      )}
      {...props}
    />
    </SegmentedControlContext.Provider>
  )
}

function SegmentedControlItem({
  className,
  value,
  onFocus,
  ...props
}: React.ComponentProps<typeof ToggleGroupPrimitive.Item>) {
  const select = React.useContext(SegmentedControlContext)
  return (
    <ToggleGroupPrimitive.Item
      data-slot="segmented-control-item"
      value={value}
      onFocus={(event) => {
        onFocus?.(event)
        if (event.currentTarget.matches(":focus-visible")) select(value)
      }}
      className={cn(
        "relative z-10 m-0 inline-flex min-h-(--seg-height) flex-1 cursor-pointer items-center justify-center gap-(--seg-gap) rounded-(--newspack-ui-radius-segment-inner) bg-transparent px-(--seg-px) py-(--seg-py) font-(family-name:--newspack-ui-button-font-family) text-(length:--seg-fs) leading-(--seg-lh) font-(weight:--newspack-ui-font-weight-medium) tracking-normal whitespace-nowrap normal-case text-(color:--newspack-ui-color-text-muted) outline-2 outline-transparent",
        "[transition:color_var(--newspack-ui-duration-color)_ease,box-shadow_var(--newspack-ui-duration-color)_ease]",
        "hover:text-(color:--newspack-ui-color-text)",
        "data-[state=on]:text-(color:--newspack-ui-color-text) forced-colors:data-[state=on]:outline-1 forced-colors:data-[state=on]:-outline-offset-1 forced-colors:data-[state=on]:outline-[Highlight]",
        "focus-visible:transition-none focus-visible:[box-shadow:inset_0_0_0_2px_var(--newspack-ui-color-focus),0_0_0_var(--newspack-ui-focus-halo-width)_var(--newspack-ui-color-focus-halo)]",
        "disabled:pointer-events-none disabled:cursor-default disabled:text-(color:--newspack-ui-color-disabled-ink) [&_svg]:pointer-events-none [&_svg]:size-(--newspack-ui-spacer-5) [&_svg]:shrink-0",
        className
      )}
      {...props}
    />
  )
}

export { SegmentedControl, SegmentedControlItem }
