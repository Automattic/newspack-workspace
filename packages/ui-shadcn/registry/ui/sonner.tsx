import * as React from "react"
import { Toaster as Sonner, type ToasterProps } from "sonner"
import { cn } from "@/lib/utils"
import {
  CloseIcon,
  ErrorIcon,
  InfoIcon,
  SuccessIcon,
  WarningIcon,
} from "@/lib/newspack-ui-icons"

const inkHover =
  "color-mix(in_oklab,var(--newspack-ui-color-inverse-bg)_var(--newspack-ui-color-mix-hover),var(--newspack-ui-color-inverse-ink))"
const inkPressed =
  "color-mix(in_oklab,var(--newspack-ui-color-inverse-bg)_var(--newspack-ui-color-mix-pressed),var(--newspack-ui-color-inverse-ink))"
const focusRing =
  "focus-visible:[box-shadow:inset_0_0_0_2px_var(--newspack-ui-color-inverse-ink),0_0_0_var(--newspack-ui-focus-halo-width)_color-mix(in_oklab,var(--newspack-ui-color-inverse-ink)_var(--newspack-ui-focus-halo-mix),transparent)]"
const ghostDark = `[transition:background-color_var(--newspack-ui-duration-color)_ease,transform_var(--newspack-ui-duration-press)_var(--newspack-ui-ease-out)] hover:bg-[${inkHover}] active:scale-[0.97] active:bg-[${inkPressed}] motion-reduce:active:scale-100 focus-visible:transition-none ${focusRing}`

const classNames = {
  toast:
    "relative flex w-(--width) items-center overflow-hidden rounded-(--newspack-ui-radius-control) bg-(--newspack-ui-color-inverse-bg) font-(family-name:--newspack-ui-font-family-body) text-(length:--newspack-ui-font-size-body) leading-(--newspack-ui-line-height-body) text-(color:--newspack-ui-color-inverse-ink) [box-shadow:var(--nui-elevation-3)] forced-colors:outline forced-colors:outline-1 forced-colors:-outline-offset-1 forced-colors:outline-[CanvasText]",
  icon: "order-1 flex shrink-0 self-start p-(--newspack-ui-spacer-2) pe-0 [&_svg]:block [&_svg]:size-(--newspack-ui-spacer-5) [&_svg]:fill-(--newspack-ui-color-inverse-ink)",
  content:
    "order-2 flex min-w-0 flex-1 flex-col px-(--newspack-ui-spacer-3) py-(--newspack-ui-spacer-2) [&_a]:font-(weight:--newspack-ui-font-weight-strong) [&_a]:underline [&_a]:underline-offset-[3px]",
  title: "text-inherit",
  description:
    "text-(length:--newspack-ui-font-size-small) leading-(--newspack-ui-line-height-small)",
  actionButton: cn(
    "order-3 inline-flex min-h-(--newspack-ui-spacer-7) shrink-0 cursor-pointer items-center self-center rounded-(--newspack-ui-radius-button) bg-transparent px-[calc(var(--newspack-ui-spacer-1)*1.75)] me-(--newspack-ui-spacer-1) text-(length:--newspack-ui-font-size-small) leading-(--newspack-ui-line-height-small) font-(family-name:--newspack-ui-button-font-family) font-(weight:--newspack-ui-button-font-weight) text-(color:--newspack-ui-color-inverse-ink) underline underline-offset-[3px] outline-2 outline-transparent",
    ghostDark
  ),
  cancelButton: cn(
    "order-3 inline-flex min-h-(--newspack-ui-spacer-7) shrink-0 cursor-pointer items-center self-center rounded-(--newspack-ui-radius-button) bg-transparent px-[calc(var(--newspack-ui-spacer-1)*1.75)] me-(--newspack-ui-spacer-1) text-(length:--newspack-ui-font-size-small) leading-(--newspack-ui-line-height-small) font-(family-name:--newspack-ui-button-font-family) font-(weight:--newspack-ui-button-font-weight) text-(color:--newspack-ui-color-inverse-ink) outline-2 outline-transparent",
    ghostDark
  ),
  closeButton: cn(
    "order-4 me-(--newspack-ui-spacer-half) mt-(--newspack-ui-spacer-half) inline-flex size-(--newspack-ui-spacer-8) shrink-0 cursor-pointer items-center justify-center self-start rounded-[max(var(--newspack-ui-border-radius-s),calc(var(--newspack-ui-radius-control)-var(--newspack-ui-spacer-half)))] border-0 bg-transparent p-0 text-(color:--newspack-ui-color-inverse-ink) outline-2 outline-transparent [&_svg]:size-(--newspack-ui-spacer-5) [&_svg]:fill-(--newspack-ui-color-inverse-ink)",
    ghostDark
  ),
}

function Toaster({ className, style, toastOptions, ...props }: ToasterProps) {
  const merged = { ...classNames } as Record<string, string>
  for (const [key, value] of Object.entries(toastOptions?.classNames ?? {})) {
    merged[key] = cn(merged[key], value)
  }

  return (
    <Sonner
      data-slot="toaster"
      position="bottom-center"
      duration={8000}
      gap={8}
      closeButton
      offset="var(--newspack-ui-spacer-5)"
      mobileOffset={{
        left: "var(--newspack-ui-spacer-3)",
        right: "var(--newspack-ui-spacer-3)",
        bottom: "var(--newspack-ui-spacer-5)",
      }}
      className={cn(
        "toaster group font-(family-name:--newspack-ui-font-family-body)",
        className
      )}
      icons={{
        success: <SuccessIcon />,
        info: <InfoIcon />,
        warning: <WarningIcon />,
        error: <ErrorIcon />,
        close: <CloseIcon />,
      }}
      toastOptions={{ ...toastOptions, unstyled: true, classNames: merged }}
      style={
        {
          "--width": "var(--newspack-ui-width-s)",
          "--gray11": "var(--newspack-ui-color-inverse-ink)",
          ...style,
        } as React.CSSProperties
      }
      {...props}
    />
  )
}

export { Toaster }
