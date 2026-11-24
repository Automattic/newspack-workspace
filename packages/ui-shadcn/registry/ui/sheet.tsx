import * as React from "react"
import { cn } from "@/lib/utils"
import { Dialog as SheetPrimitive } from "radix-ui"

import { Button } from "@/components/ui/button"
import { CloseIcon } from "@/lib/newspack-ui-icons"

function Sheet({ ...props }: React.ComponentProps<typeof SheetPrimitive.Root>) {
  return <SheetPrimitive.Root data-slot="sheet" {...props} />
}

function SheetTrigger({
  ...props
}: React.ComponentProps<typeof SheetPrimitive.Trigger>) {
  return <SheetPrimitive.Trigger data-slot="sheet-trigger" {...props} />
}

function SheetClose({
  ...props
}: React.ComponentProps<typeof SheetPrimitive.Close>) {
  return <SheetPrimitive.Close data-slot="sheet-close" {...props} />
}

function SheetPortal({
  ...props
}: React.ComponentProps<typeof SheetPrimitive.Portal>) {
  return <SheetPrimitive.Portal data-slot="sheet-portal" {...props} />
}

function SheetOverlay({
  className,
  ...props
}: React.ComponentProps<typeof SheetPrimitive.Overlay>) {
  return (
    <SheetPrimitive.Overlay
      data-slot="sheet-overlay"
      className={cn(
        "fixed inset-0 z-50 bg-(--newspack-ui-color-overlay-50) duration-(--newspack-ui-duration-sheet) ease-(--newspack-ui-ease-drawer) data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:animate-in data-[state=open]:fade-in-0",
        className
      )}
      {...props}
    />
  )
}

function SheetContent({
  className,
  children,
  side = "right",
  showCloseButton = true,
  container,
  ...props
}: React.ComponentProps<typeof SheetPrimitive.Content> & {
  side?: "top" | "right" | "bottom" | "left"
  showCloseButton?: boolean
  container?: HTMLElement | null
}) {
  return (
    <SheetPortal container={container}>
      <SheetOverlay />
      <SheetPrimitive.Content
        data-slot="sheet-content"
        data-side={side}
        className={cn(
          "fixed z-50 flex flex-col overflow-auto overscroll-contain bg-(--newspack-ui-color-surface) font-(family-name:--newspack-ui-font-family-body) text-(length:--newspack-ui-font-size-body) leading-(--newspack-ui-line-height-body) text-(color:--newspack-ui-color-text) duration-(--newspack-ui-duration-sheet) outline-none [box-shadow:var(--nui-elevation-4)] ease-(--newspack-ui-ease-drawer) data-[state=closed]:animate-out data-[state=open]:animate-in motion-reduce:data-[state=closed]:fade-out-0 motion-reduce:data-[state=open]:fade-in-0",
          side === "right" &&
            "inset-y-0 right-0 h-full w-[min(var(--newspack-ui-width-s),calc(100vw-var(--newspack-ui-spacer-6)))] motion-safe:data-[state=closed]:slide-out-to-right motion-safe:data-[state=open]:slide-in-from-right",
          side === "left" &&
            "inset-y-0 left-0 h-full w-[min(var(--newspack-ui-width-s),calc(100vw-var(--newspack-ui-spacer-6)))] motion-safe:data-[state=closed]:slide-out-to-left motion-safe:data-[state=open]:slide-in-from-left",
          side === "top" &&
            "inset-x-0 top-0 max-h-[calc(100%-var(--newspack-ui-spacer-6))] motion-safe:data-[state=closed]:slide-out-to-top motion-safe:data-[state=open]:slide-in-from-top",
          side === "bottom" &&
            "inset-x-0 bottom-0 max-h-[calc(100%-var(--newspack-ui-spacer-6))] pb-[env(safe-area-inset-bottom)] motion-safe:data-[state=closed]:slide-out-to-bottom motion-safe:data-[state=open]:slide-in-from-bottom",
          className
        )}
        {...props}
      >
        {children}
        {showCloseButton && (
          <SheetPrimitive.Close asChild>
            <Button
              variant="ghost"
              size="icon"
              className="absolute end-[calc(var(--newspack-ui-spacer-5)-(var(--newspack-ui-touch-target)-var(--newspack-ui-spacer-4))/2)] top-[calc(var(--newspack-ui-spacer-5)+(max(18px,var(--newspack-ui-font-size-title-m))*var(--newspack-ui-line-height-title-m)-var(--newspack-ui-touch-target))/2)] size-(--newspack-ui-touch-target) rounded-(--newspack-ui-radius-pill) [&_svg]:size-(--newspack-ui-spacer-4)"
            >
              <CloseIcon />
              <span className="sr-only">Close</span>
            </Button>
          </SheetPrimitive.Close>
        )}
      </SheetPrimitive.Content>
    </SheetPortal>
  )
}

function SheetHeader({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="sheet-header"
      className={cn(
        "flex flex-col p-(--newspack-ui-spacer-5) pe-[calc(var(--newspack-ui-spacer-5)+(var(--newspack-ui-touch-target)+var(--newspack-ui-spacer-4))/2+var(--newspack-ui-spacer-3))]",
        className
      )}
      {...props}
    />
  )
}

function SheetFooter({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="sheet-footer"
      className={cn(
        "mt-auto flex flex-col gap-(--newspack-ui-spacer-2) p-(--newspack-ui-spacer-5) *:w-full",
        className
      )}
      {...props}
    />
  )
}

function SheetTitle({
  className,
  ...props
}: React.ComponentProps<typeof SheetPrimitive.Title>) {
  return (
    <SheetPrimitive.Title
      data-slot="sheet-title"
      className={cn(
        "font-(family-name:--newspack-ui-font-family-heading) text-[max(18px,var(--newspack-ui-font-size-title-m))] leading-(--newspack-ui-line-height-title-m) font-(weight:--newspack-ui-font-weight-strong) tracking-(--newspack-ui-letter-spacing-title-m)",
        className
      )}
      {...props}
    />
  )
}

function SheetDescription({
  className,
  ...props
}: React.ComponentProps<typeof SheetPrimitive.Description>) {
  return (
    <SheetPrimitive.Description
      data-slot="sheet-description"
      className={cn(
        "mt-(--newspack-ui-spacer-2) text-(length:--newspack-ui-font-size-small) leading-(--newspack-ui-line-height-small) text-(color:--newspack-ui-color-text-muted)",
        className
      )}
      {...props}
    />
  )
}

export {
  Sheet,
  SheetClose,
  SheetContent,
  SheetDescription,
  SheetFooter,
  SheetHeader,
  SheetOverlay,
  SheetPortal,
  SheetTitle,
  SheetTrigger,
}
