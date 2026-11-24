import * as React from "react"
import { cva, type VariantProps } from "class-variance-authority"
import { cn } from "@/lib/utils"
import { Slot } from "radix-ui"

const buttonVariants = cva(
  [
    "inline-flex shrink-0 cursor-pointer items-center justify-center whitespace-nowrap outline-2 outline-transparent",
    "rounded-(--newspack-ui-radius-button) bg-(--btn-bg) text-(color:--btn-ink) shadow-[inset_0_0_0_1px_var(--btn-border)]",
    "min-h-(--btn-height) gap-(--btn-gap) px-(--btn-px) py-(--btn-py) text-(length:--btn-fs) leading-(--btn-lh)",
    "font-(family-name:--newspack-ui-button-font-family) font-(weight:--newspack-ui-button-font-weight) tracking-(--newspack-ui-button-letter-spacing) [text-transform:var(--newspack-ui-button-text-transform)]",
    "[--btn-height:var(--newspack-ui-spacer-9)] [--btn-py:var(--newspack-ui-spacer-2)] [--btn-px:var(--newspack-ui-spacer-4)] [--btn-gap:var(--newspack-ui-spacer-1)] [--btn-fs:var(--newspack-ui-font-size-body)] [--btn-lh:var(--newspack-ui-line-height-body)]",
    "[--btn-border:transparent] [--btn-border-hover:var(--btn-border)] [--btn-ink-pressed:var(--btn-ink)] [--btn-bg-disabled:var(--newspack-ui-color-surface-subtle)] [--btn-ink-disabled:var(--newspack-ui-color-disabled-ink)] [--btn-focus:var(--newspack-ui-color-focus)]",
    "[transition:background-color_var(--newspack-ui-duration-color)_ease,color_var(--newspack-ui-duration-color)_ease,box-shadow_var(--newspack-ui-duration-color)_ease,transform_var(--newspack-ui-duration-press)_var(--newspack-ui-ease-out)]",
    "hover:bg-(--btn-bg-hover) hover:shadow-[inset_0_0_0_1px_var(--btn-border-hover)]",
    "active:scale-[0.97] active:bg-(--btn-bg-pressed) active:text-(color:--btn-ink-pressed) motion-reduce:active:scale-100",
    "focus-visible:transition-none focus-visible:[box-shadow:inset_0_0_0_2px_var(--btn-focus),0_0_0_var(--newspack-ui-focus-halo-width)_var(--newspack-ui-color-focus-halo)]",
    "disabled:pointer-events-none disabled:cursor-default disabled:scale-100 disabled:bg-(--btn-bg-disabled) disabled:text-(color:--btn-ink-disabled) disabled:shadow-none disabled:[--btn-focus:var(--btn-ink-disabled)]",
    "[&_svg]:pointer-events-none [&_svg]:size-(--newspack-ui-spacer-5) [&_svg]:shrink-0",
  ],
  {
    variants: {
      variant: {
        default:
          "[--btn-bg:var(--newspack-ui-color-btn)] [--btn-bg-hover:var(--newspack-ui-color-btn-hover)] [--btn-bg-pressed:var(--newspack-ui-color-btn-pressed)] [--btn-ink:var(--newspack-ui-color-btn-ink)] [--btn-focus:var(--newspack-ui-color-focus-on-brand)] hover:text-(color:--newspack-ui-color-button-text-hover)",
        brand:
          "[--btn-bg:var(--newspack-ui-color-btn-brand)] [--btn-bg-hover:var(--newspack-ui-color-btn-brand-hover)] [--btn-bg-pressed:var(--newspack-ui-color-btn-brand-pressed)] [--btn-ink:var(--newspack-ui-color-btn-brand-on)] [--btn-focus:var(--newspack-ui-color-btn-brand-on)]",
        "brand-outline":
          "[--btn-bg:var(--newspack-ui-color-surface)] [--btn-bg-hover:var(--newspack-ui-color-neutral-fill)] [--btn-bg-pressed:var(--newspack-ui-color-neutral-fill-hover)] [--btn-ink:var(--newspack-ui-color-btn-brand-text)] [--btn-border:var(--newspack-ui-color-btn-brand)] [--btn-focus:var(--newspack-ui-color-btn-brand-text)]",
        secondary:
          "[--btn-bg:var(--newspack-ui-color-neutral-fill)] [--btn-bg-hover:var(--newspack-ui-color-neutral-fill-hover)] [--btn-bg-pressed:var(--newspack-ui-color-neutral-fill-pressed)] [--btn-ink:var(--newspack-ui-color-text)]",
        outline:
          "[--btn-bg:transparent] [--btn-bg-hover:var(--newspack-ui-color-neutral-fill)] [--btn-bg-pressed:var(--newspack-ui-color-neutral-fill-hover)] [--btn-ink:var(--newspack-ui-color-text)] [--btn-border:var(--newspack-ui-color-border-outline)]",
        ghost:
          "[--btn-bg:transparent] [--btn-bg-hover:var(--newspack-ui-color-neutral-fill)] [--btn-bg-pressed:var(--newspack-ui-color-neutral-fill-hover)] [--btn-bg-disabled:transparent] [--btn-ink:var(--newspack-ui-color-text)]",
        destructive:
          "[--btn-bg:var(--newspack-ui-color-destructive-fill)] [--btn-bg-hover:var(--newspack-ui-color-destructive-fill-hover)] [--btn-bg-pressed:var(--newspack-ui-color-destructive-fill-pressed)] [--btn-ink:var(--newspack-ui-color-destructive-ink)] [--btn-border:var(--newspack-ui-color-destructive-edge)] [--btn-focus:var(--newspack-ui-color-destructive-ink)]",
        link: "min-h-0 rounded-[calc(var(--newspack-ui-radius-control)*0.5)] p-0 text-(length:--newspack-ui-font-size-small) leading-(--newspack-ui-line-height-small) font-(weight:--newspack-ui-font-weight-medium) text-(color:--newspack-ui-color-text) underline decoration-[color-mix(in_oklab,var(--newspack-ui-color-text)_50%,var(--newspack-ui-color-surface))] underline-offset-[3px] shadow-none hover:bg-transparent hover:decoration-(color:--newspack-ui-color-text) active:scale-100",
      },
      size: {
        default: "",
        sm: "[--btn-height:var(--newspack-ui-spacer-7)] [--btn-py:var(--newspack-ui-spacer-1)] [--btn-px:calc(var(--newspack-ui-spacer-1)*1.75)] [--btn-gap:calc(var(--newspack-ui-spacer-1)*0.75)] [--btn-fs:var(--newspack-ui-font-size-small)] [--btn-lh:var(--newspack-ui-line-height-small)]",
        xs: "[--btn-height:calc(var(--newspack-ui-spacer-1)*3.5)] [--btn-py:var(--newspack-ui-spacer-half)] [--btn-px:calc(var(--newspack-ui-spacer-1)*1.25)] [--btn-gap:calc(var(--newspack-ui-spacer-1)*0.75)] [--btn-fs:var(--newspack-ui-font-size-small)] [--btn-lh:var(--newspack-ui-line-height-small)] rounded-[calc(var(--newspack-ui-radius-button)*0.75)]",
        icon: "size-(--newspack-ui-spacer-8) min-h-0 p-0",
        "icon-sm": "size-(--newspack-ui-spacer-7) min-h-0 p-0",
        "icon-xs": "size-[calc(var(--newspack-ui-spacer-1)*3.5)] min-h-0 rounded-[calc(var(--newspack-ui-radius-button)*0.75)] p-0",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  }
)

function Button({
  className,
  variant = "default",
  size = "default",
  asChild = false,
  ...props
}: React.ComponentProps<"button"> &
  VariantProps<typeof buttonVariants> & {
    asChild?: boolean
  }) {
  const Comp = asChild ? Slot.Root : "button"

  return (
    <Comp
      data-slot="button"
      data-variant={variant}
      data-size={size}
      className={cn(buttonVariants({ variant, size, className }))}
      {...props}
    />
  )
}

export { Button, buttonVariants }
