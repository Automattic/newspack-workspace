import * as React from "react"
import { cn } from "@/lib/utils"

function Card({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="card"
      className={cn(
        "flex flex-col gap-(--newspack-ui-spacer-2) rounded-(--newspack-ui-radius-surface) border border-(color:--newspack-ui-color-border) bg-transparent p-(--newspack-ui-spacer-4) text-(color:--newspack-ui-color-text)",
        className
      )}
      {...props}
    />
  )
}

function CardHeader({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="card-header"
      className={cn(
        "@container/card-header grid auto-rows-min grid-rows-[auto_auto] items-start gap-x-(--newspack-ui-spacer-2) gap-y-(--newspack-ui-spacer-1) has-data-[slot=card-action]:grid-cols-[1fr_auto]",
        className
      )}
      {...props}
    />
  )
}

function CardTitle({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="card-title"
      className={cn(
        "text-(length:--newspack-ui-font-size-m) leading-(--newspack-ui-line-height-m) font-(weight:--newspack-ui-font-weight-strong) tracking-(--newspack-ui-letter-spacing-title-m)",
        className
      )}
      {...props}
    />
  )
}

function CardDescription({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="card-description"
      className={cn(
        "text-(color:--newspack-ui-color-text-muted) text-(length:--newspack-ui-font-size-small) leading-(--newspack-ui-line-height-small)",
        className
      )}
      {...props}
    />
  )
}

function CardAction({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="card-action"
      className={cn(
        "col-start-2 row-span-2 row-start-1 flex min-h-[calc(var(--newspack-ui-font-size-m)*var(--newspack-ui-line-height-m))] items-center self-start justify-self-end",
        className
      )}
      {...props}
    />
  )
}

function CardContent({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="card-content"
      className={cn(
        "text-(length:--newspack-ui-font-size-body) leading-(--newspack-ui-line-height-body)",
        className
      )}
      {...props}
    />
  )
}

function CardFooter({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="card-footer"
      className={cn("flex items-center", className)}
      {...props}
    />
  )
}

export {
  Card,
  CardHeader,
  CardFooter,
  CardTitle,
  CardAction,
  CardDescription,
  CardContent,
}
