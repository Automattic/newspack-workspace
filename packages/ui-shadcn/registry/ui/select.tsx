"use client"

import * as React from "react"
import { cn } from "@/lib/utils"
import { field } from "@/lib/newspack-ui"
import { Select as SelectPrimitive } from "radix-ui"

function Select({
  ...props
}: React.ComponentProps<typeof SelectPrimitive.Root>) {
  return <SelectPrimitive.Root data-slot="select" {...props} />
}

function SelectGroup({
  ...props
}: React.ComponentProps<typeof SelectPrimitive.Group>) {
  return <SelectPrimitive.Group data-slot="select-group" {...props} />
}

function SelectValue({
  ...props
}: React.ComponentProps<typeof SelectPrimitive.Value>) {
  return <SelectPrimitive.Value data-slot="select-value" {...props} />
}

function SelectTrigger({
  className,
  children,
  ...props
}: React.ComponentProps<typeof SelectPrimitive.Trigger>) {
  return (
    <SelectPrimitive.Trigger
      data-slot="select-trigger"
      className={cn(
        field,
        "relative flex min-h-(--newspack-ui-spacer-9) cursor-pointer items-center justify-between gap-(--newspack-ui-spacer-1) py-[calc(var(--newspack-ui-spacer-2)-1px)] ps-(--newspack-ui-control-padding-inline) pe-[calc(var(--newspack-ui-spacer-1)+var(--newspack-ui-spacer-5)+var(--newspack-ui-spacer-1)*1.25)] text-start data-[placeholder]:text-(color:--newspack-ui-color-text-subtle) *:data-[slot=select-value]:line-clamp-1 disabled:cursor-not-allowed",
        className
      )}
      {...props}
    >
      {children}
      <SelectPrimitive.Icon
        aria-hidden="true"
        className="pointer-events-none absolute end-(--newspack-ui-spacer-1) top-1/2 size-(--newspack-ui-spacer-5) -translate-y-1/2 bg-current [mask:var(--newspack-ui-field-caret-dark)_center/contain_no-repeat]"
      />
    </SelectPrimitive.Trigger>
  )
}

function SelectContent({
  className,
  children,
  position = "popper",
  ...props
}: React.ComponentProps<typeof SelectPrimitive.Content>) {
  return (
    <SelectPrimitive.Portal>
      <SelectPrimitive.Content
        data-slot="select-content"
        className={cn(
          "relative z-50 max-h-(--radix-select-content-available-height) min-w-[8rem] origin-(--radix-select-content-transform-origin) overflow-x-hidden overflow-y-auto rounded-(--newspack-ui-radius-control) border border-(color:--newspack-ui-color-border) bg-(--newspack-ui-color-surface) text-(color:--newspack-ui-color-text) [box-shadow:var(--nui-elevation-2)] data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:animate-in data-[state=open]:fade-in-0",
          position === "popper" &&
            "data-[side=bottom]:translate-y-(--newspack-ui-spacer-half) data-[side=top]:-translate-y-(--newspack-ui-spacer-half)",
          className
        )}
        position={position}
        {...props}
      >
        <SelectPrimitive.Viewport
          className={cn(
            position === "popper" &&
              "h-(--radix-select-trigger-height) w-full min-w-(--radix-select-trigger-width) scroll-my-1"
          )}
        >
          {children}
        </SelectPrimitive.Viewport>
      </SelectPrimitive.Content>
    </SelectPrimitive.Portal>
  )
}

function SelectLabel({
  className,
  ...props
}: React.ComponentProps<typeof SelectPrimitive.Label>) {
  return (
    <SelectPrimitive.Label
      data-slot="select-label"
      className={cn(
        "px-(--newspack-ui-control-padding-inline) py-(--newspack-ui-spacer-1) text-(length:--newspack-ui-font-size-small) leading-(--newspack-ui-line-height-small) text-(color:--newspack-ui-color-text-muted)",
        className
      )}
      {...props}
    />
  )
}

function SelectItem({
  className,
  children,
  ...props
}: React.ComponentProps<typeof SelectPrimitive.Item>) {
  return (
    <SelectPrimitive.Item
      data-slot="select-item"
      className={cn(
        "relative box-border flex w-full cursor-pointer items-center gap-(--newspack-ui-spacer-1) ps-(--newspack-ui-control-padding-inline) pe-[calc(var(--newspack-ui-control-padding-inline)+var(--newspack-ui-spacer-4)+var(--newspack-ui-spacer-1))] py-(--newspack-ui-spacer-1) text-(length:--newspack-ui-font-size-body) leading-(--newspack-ui-line-height-body) outline-hidden select-none data-[state=checked]:not-data-[highlighted]:bg-(--newspack-ui-color-neutral-fill) data-[highlighted]:bg-(--newspack-ui-color-selected) data-[highlighted]:text-(color:--newspack-ui-color-on-selected) data-[disabled]:pointer-events-none data-[disabled]:text-(color:--newspack-ui-color-text-subtle)",
        className
      )}
      {...props}
    >
      <SelectPrimitive.ItemText>{children}</SelectPrimitive.ItemText>
      <SelectPrimitive.ItemIndicator
        data-slot="select-item-indicator"
        className="pointer-events-none absolute end-(--newspack-ui-control-padding-inline) top-1/2 grid -translate-y-1/2 place-content-center"
      >
        <svg
          viewBox="0 0 24 24"
          aria-hidden="true"
          className="size-(--newspack-ui-spacer-4) fill-current"
        >
          <path d="M16.7 7.1l-6.3 8.5-3.3-2.5-.9 1.2 4.5 3.4L17.9 8z" />
        </svg>
      </SelectPrimitive.ItemIndicator>
    </SelectPrimitive.Item>
  )
}

function SelectSeparator({
  className,
  ...props
}: React.ComponentProps<typeof SelectPrimitive.Separator>) {
  return (
    <SelectPrimitive.Separator
      data-slot="select-separator"
      className={cn(
        "pointer-events-none my-(--newspack-ui-spacer-half) h-px bg-(--newspack-ui-color-border)",
        className
      )}
      {...props}
    />
  )
}

export {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectSeparator,
  SelectTrigger,
  SelectValue,
}
