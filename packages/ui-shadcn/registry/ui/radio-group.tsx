"use client"

import * as React from "react"
import { cn } from "@/lib/utils"
import { focusLayers } from "@/lib/newspack-ui"
import { RadioGroup as RadioGroupPrimitive } from "radix-ui"

function RadioGroup({
  className,
  ...props
}: React.ComponentProps<typeof RadioGroupPrimitive.Root>) {
  return (
    <RadioGroupPrimitive.Root
      data-slot="radio-group"
      className={cn("grid gap-(--newspack-ui-spacer-2)", className)}
      {...props}
    />
  )
}

function RadioGroupItem({
  className,
  ...props
}: React.ComponentProps<typeof RadioGroupPrimitive.Item>) {
  return (
    <RadioGroupPrimitive.Item
      data-slot="radio-group-item"
      className={cn(
        focusLayers,
        "peer aspect-square size-(--newspack-ui-control-size) shrink-0 cursor-pointer rounded-full border border-(color:--nui-border) bg-(--newspack-ui-color-input-bg)",
        "[--nui-border:var(--newspack-ui-color-input-border)] transition-[background-color,border-color,box-shadow] duration-(--newspack-ui-duration-color) ease-[ease]",
        "hover:not-data-[state=checked]:not-disabled:[--nui-border:var(--newspack-ui-color-text)] focus-visible:[--nui-border:var(--nui-focus)]",
        "data-[state=checked]:border-(color:--newspack-ui-color-mark) data-[state=checked]:[--nui-inner:inset_0_0_0_5px_var(--newspack-ui-color-mark)] data-[state=checked]:focus-visible:[--nui-focus:var(--newspack-ui-color-surface)] data-[state=checked]:focus-visible:[--nui-focus-w:2px]",
        "disabled:cursor-not-allowed disabled:border-dashed disabled:border-(color:--newspack-ui-color-border) disabled:bg-(--newspack-ui-color-surface-sunken)",
        "disabled:data-[state=checked]:border-solid disabled:data-[state=checked]:border-(color:--newspack-ui-color-text-subtle) disabled:data-[state=checked]:bg-(--newspack-ui-color-surface) disabled:data-[state=checked]:[--nui-inner:inset_0_0_0_5px_var(--newspack-ui-color-text-subtle)]",
        "aria-invalid:border-(color:--newspack-ui-color-error) aria-invalid:[--nui-focus:var(--newspack-ui-color-error)] aria-invalid:[--nui-outer:0_0_0_1px_var(--newspack-ui-color-error)] aria-invalid:focus-visible:[--nui-focus-w:2px]",
        className
      )}
      {...props}
    >
      <RadioGroupPrimitive.Indicator data-slot="radio-group-indicator" />
    </RadioGroupPrimitive.Item>
  )
}

export { RadioGroup, RadioGroupItem }
