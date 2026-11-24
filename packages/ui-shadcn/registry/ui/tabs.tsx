"use client"

import * as React from "react"
import { cn } from "@/lib/utils"
import { Tabs as TabsPrimitive } from "radix-ui"

function Tabs({
  className,
  orientation = "horizontal",
  ...props
}: React.ComponentProps<typeof TabsPrimitive.Root>) {
  return (
    <TabsPrimitive.Root
      data-slot="tabs"
      data-orientation={orientation}
      orientation={orientation}
      className={cn("group/tabs flex flex-col", className)}
      {...props}
    />
  )
}

function TabsList({
  className,
  ...props
}: React.ComponentProps<typeof TabsPrimitive.List>) {
  return (
    <TabsPrimitive.List
      data-slot="tabs-list"
      className={cn(
        "flex flex-row flex-wrap shadow-[inset_0_-1px_0_var(--newspack-ui-color-border)]",
        className
      )}
      {...props}
    />
  )
}

function TabsTrigger({
  className,
  ...props
}: React.ComponentProps<typeof TabsPrimitive.Trigger>) {
  return (
    <TabsPrimitive.Trigger
      data-slot="tabs-trigger"
      className={cn(
        "inline-flex min-h-(--newspack-ui-spacer-9) flex-none cursor-pointer items-center gap-(--newspack-ui-spacer-1) px-(--newspack-ui-spacer-3) font-(family-name:--newspack-ui-font-family-control) text-(length:--newspack-ui-font-size-body) leading-(--newspack-ui-line-height-body) font-(weight:--newspack-ui-font-weight-medium) whitespace-nowrap text-(color:--newspack-ui-color-text-subtle) outline-2 outline-transparent",
        "[--tab-indicator:0_0_transparent] [transition:color_var(--newspack-ui-duration-color)_ease,box-shadow_var(--newspack-ui-duration-color)_ease] [box-shadow:var(--tab-indicator)]",
        "hover:text-(color:--newspack-ui-color-text)",
        "data-[state=active]:text-(color:--newspack-ui-color-text) data-[state=active]:[--tab-indicator:inset_0_-2px_0_var(--newspack-ui-color-selected)]",
        "focus-visible:rounded-(--newspack-ui-radius-tab-inner) focus-visible:transition-none focus-visible:[box-shadow:var(--tab-indicator),inset_0_0_0_2px_var(--newspack-ui-color-focus),inset_0_0_0_calc(2px+var(--newspack-ui-focus-halo-width))_var(--newspack-ui-color-focus-halo)]",
        "disabled:pointer-events-none disabled:text-(color:--newspack-ui-color-disabled-ink) [&_svg]:pointer-events-none [&_svg]:shrink-0",
        className
      )}
      {...props}
    />
  )
}

function TabsContent({
  className,
  ...props
}: React.ComponentProps<typeof TabsPrimitive.Content>) {
  return (
    <TabsPrimitive.Content
      data-slot="tabs-content"
      className={cn(
        "my-(--newspack-ui-spacer-6) flex-1 outline-none",
        className
      )}
      {...props}
    />
  )
}

export { Tabs, TabsList, TabsTrigger, TabsContent }
