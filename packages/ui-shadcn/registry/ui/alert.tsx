import * as React from "react"
import { cva, type VariantProps } from "class-variance-authority"
import { cn } from "@/lib/utils"

const alertVariants = cva(
  "relative grid w-full min-h-(--newspack-ui-spacer-9) grid-cols-[0_1fr] items-start gap-y-0 rounded-(--newspack-ui-radius-control) border border-(color:--alert-border) bg-(--alert-bg) px-(--newspack-ui-spacer-3) py-(--newspack-ui-spacer-2) font-(family-name:--newspack-ui-font-family-body) text-(length:--newspack-ui-font-size-body) leading-(--newspack-ui-line-height-body) text-(color:--newspack-ui-color-text) has-[>svg]:grid-cols-[var(--newspack-ui-spacer-5)_1fr] has-[>svg]:gap-x-(--newspack-ui-spacer-2) [&>svg]:mx-[calc(var(--newspack-ui-spacer-half)/-2)] [&>svg]:size-(--newspack-ui-spacer-5) [&>svg]:fill-(--alert-icon)",
  {
    variants: {
      variant: {
        default:
          "[--alert-bg:var(--newspack-ui-color-surface-subtle)] [--alert-border:var(--newspack-ui-color-border)] [--alert-icon:var(--newspack-ui-color-text-muted)]",
        info: "[--alert-bg:var(--newspack-ui-color-surface-subtle)] [--alert-border:var(--newspack-ui-color-border)] [--alert-icon:var(--newspack-ui-color-text-muted)]",
        success:
          "[--alert-bg:var(--newspack-ui-color-success-tint)] [--alert-border:var(--newspack-ui-color-success-border)] [--alert-icon:var(--newspack-ui-color-success)]",
        warning:
          "[--alert-bg:var(--newspack-ui-color-warning-tint)] [--alert-border:var(--newspack-ui-color-warning-border)] [--alert-icon:var(--newspack-ui-color-warning-dot)]",
        error:
          "[--alert-bg:var(--newspack-ui-color-error-tint)] [--alert-border:var(--newspack-ui-color-error-border)] [--alert-icon:var(--newspack-ui-color-error)]",
        destructive:
          "[--alert-bg:var(--newspack-ui-color-error-tint)] [--alert-border:var(--newspack-ui-color-error-border)] [--alert-icon:var(--newspack-ui-color-error)]",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  }
)

function Alert({
  className,
  variant,
  ...props
}: React.ComponentProps<"div"> & VariantProps<typeof alertVariants>) {
  return (
    <div
      data-slot="alert"
      role="alert"
      className={cn(alertVariants({ variant }), className)}
      {...props}
    />
  )
}

function AlertTitle({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="alert-title"
      className={cn(
        "col-start-2 font-(weight:--newspack-ui-font-weight-strong)",
        className
      )}
      {...props}
    />
  )
}

function AlertDescription({
  className,
  ...props
}: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="alert-description"
      className={cn("col-start-2 grid justify-items-start", className)}
      {...props}
    />
  )
}

export { Alert, AlertTitle, AlertDescription }
