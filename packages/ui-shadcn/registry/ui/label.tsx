import * as React from "react"
import { cn } from "@/lib/utils"
import { Label as LabelPrimitive } from "radix-ui"

import { ErrorIcon } from "@/lib/newspack-ui-icons"

function Label({
  className,
  ...props
}: React.ComponentProps<typeof LabelPrimitive.Root>) {
  return (
    <LabelPrimitive.Root
      data-slot="label"
      className={cn(
        "flex items-center gap-(--newspack-ui-spacer-1) text-(color:--newspack-ui-label-color) font-(family-name:--newspack-ui-font-family-control) text-(length:--newspack-ui-font-size-body) leading-(--newspack-ui-line-height-body) font-(weight:--newspack-ui-font-weight-medium) select-none group-data-[disabled=true]:pointer-events-none peer-disabled:cursor-not-allowed peer-disabled:text-(color:--newspack-ui-color-text-subtle)",
        className
      )}
      {...props}
    />
  )
}

function FieldHelper({ className, ...props }: React.ComponentProps<"p">) {
  return (
    <p
      data-slot="field-helper"
      className={cn(
        "text-(color:--newspack-ui-color-text-muted) font-(family-name:--newspack-ui-font-family-body) text-(length:--newspack-ui-font-size-small) leading-(--newspack-ui-line-height-small) font-(weight:--newspack-ui-font-weight-regular)",
        className
      )}
      {...props}
    />
  )
}

function FieldError({
  className,
  children,
  ...props
}: React.ComponentProps<"p">) {
  return (
    <FieldHelper
      data-slot="field-error"
      className={cn(
        "relative ps-[calc(var(--newspack-ui-spacer-3)+var(--newspack-ui-spacer-1)*0.75)] text-(color:--newspack-ui-color-error-text)",
        className
      )}
      {...props}
    >
      <ErrorIcon className="absolute start-0 top-[calc((1lh-var(--newspack-ui-spacer-3))/2)] size-(--newspack-ui-spacer-3) fill-current" />
      {children}
    </FieldHelper>
  )
}

export { Label, FieldHelper, FieldError }
