import * as React from "react"
import { cva, type VariantProps } from "class-variance-authority"
import { cn } from "@/lib/utils"
import { Slot } from "radix-ui"

const badgeVariants = cva(
  "inline-flex h-(--newspack-ui-spacer-4) w-fit shrink-0 cursor-default items-center justify-center overflow-hidden rounded-(--newspack-ui-radius-badge) bg-(--badge-bg) px-[calc(var(--newspack-ui-spacer-1)*0.75)] font-(family-name:--newspack-ui-font-family-body) text-(length:--newspack-ui-font-size-caption) leading-none font-(weight:--newspack-ui-font-weight-strong) tracking-[0.01em] whitespace-nowrap text-(color:--badge-ink) [box-shadow:var(--badge-edge)] [--badge-edge:0_0_transparent] [&>svg]:pointer-events-none [&>svg]:size-3",
  {
    variants: {
      variant: {
        default:
          "[--badge-bg:var(--newspack-ui-color-btn)] [--badge-ink:var(--newspack-ui-color-btn-ink)]",
        muted:
          "[--badge-bg:var(--newspack-ui-color-surface-sunken)] [--badge-ink:var(--newspack-ui-color-text-muted)]",
        secondary:
          "[--badge-bg:var(--newspack-ui-color-neutral-fill)] [--badge-ink:var(--newspack-ui-color-text)]",
        outline:
          "[--badge-bg:transparent] [--badge-ink:var(--newspack-ui-color-text)] [--badge-edge:inset_0_0_0_1px_var(--newspack-ui-color-border-outline)]",
        success:
          "[--badge-bg:var(--newspack-ui-color-success-tint)] [--badge-ink:var(--newspack-ui-color-success-text)]",
        warning:
          "[--badge-bg:var(--newspack-ui-color-warning-tint)] [--badge-ink:var(--newspack-ui-color-warning-text)]",
        destructive:
          "[--badge-bg:var(--newspack-ui-color-error-tint)] [--badge-ink:var(--newspack-ui-color-error-text)]",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  }
)

function Badge({
  className,
  variant = "default",
  asChild = false,
  ...props
}: React.ComponentProps<"span"> &
  VariantProps<typeof badgeVariants> & { asChild?: boolean }) {
  const Comp = asChild ? Slot.Root : "span"

  return (
    <Comp
      data-slot="badge"
      data-variant={variant}
      className={cn(badgeVariants({ variant }), className)}
      {...props}
    />
  )
}

export { Badge, badgeVariants }
