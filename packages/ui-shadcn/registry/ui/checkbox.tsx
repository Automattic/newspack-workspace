"use client"

import * as React from "react"
import { cn } from "@/lib/utils"
import { focusLayers } from "@/lib/newspack-ui"
import { Checkbox as CheckboxPrimitive } from "radix-ui"

function Checkbox({
  className,
  ...props
}: React.ComponentProps<typeof CheckboxPrimitive.Root>) {
  return (
    <CheckboxPrimitive.Root
      data-slot="checkbox"
      className={cn(
        focusLayers,
        "peer inline-grid size-(--newspack-ui-control-size) shrink-0 cursor-pointer place-content-center rounded-[calc(var(--newspack-ui-radius-control)*0.5)] border border-(color:--nui-border) bg-(--newspack-ui-color-input-bg) text-(color:--newspack-ui-color-on-mark)",
        "[--nui-border:var(--newspack-ui-color-input-border)] transition-[background-color,border-color,box-shadow] duration-(--newspack-ui-duration-color) ease-[ease]",
        "hover:not-data-[state=checked]:not-disabled:[--nui-border:var(--newspack-ui-color-text)] focus-visible:[--nui-border:var(--nui-focus)]",
        "data-[state=checked]:border-(color:--newspack-ui-color-mark) data-[state=checked]:bg-(--newspack-ui-color-mark) data-[state=checked]:focus-visible:[--nui-focus:var(--newspack-ui-color-surface)] data-[state=checked]:focus-visible:[--nui-focus-w:2px]",
        "disabled:cursor-not-allowed disabled:border-dashed disabled:border-(color:--newspack-ui-color-border) disabled:bg-(--newspack-ui-color-surface-sunken)",
        "disabled:data-[state=checked]:border-solid disabled:data-[state=checked]:border-(color:--newspack-ui-color-text-subtle) disabled:data-[state=checked]:bg-(--newspack-ui-color-text-subtle) disabled:data-[state=checked]:text-(color:--newspack-ui-color-surface)",
        "aria-invalid:border-(color:--newspack-ui-color-error) aria-invalid:[--nui-focus:var(--newspack-ui-color-error)] aria-invalid:[--nui-outer:0_0_0_1px_var(--newspack-ui-color-error)] aria-invalid:focus-visible:[--nui-focus-w:2px]",
        className
      )}
      {...props}
    >
      <CheckboxPrimitive.Indicator
        data-slot="checkbox-indicator"
        className="grid place-content-center text-current transition-none"
      >
        <svg
          viewBox="0 0 24 24"
          aria-hidden="true"
          className="size-(--newspack-ui-spacer-4) fill-current"
        >
          <path d="M16.7 7.1l-6.3 8.5-3.3-2.5-.9 1.2 4.5 3.4L17.9 8z" />
        </svg>
      </CheckboxPrimitive.Indicator>
    </CheckboxPrimitive.Root>
  )
}

export { Checkbox }
