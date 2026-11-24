import * as React from "react"
import { cn } from "@/lib/utils"
import { Dialog as DialogPrimitive } from "radix-ui"

import { Button } from "@/components/ui/button"
import { CloseIcon } from "@/lib/newspack-ui-icons"

function Dialog({
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Root>) {
  return <DialogPrimitive.Root data-slot="dialog" {...props} />
}

function DialogTrigger({
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Trigger>) {
  return <DialogPrimitive.Trigger data-slot="dialog-trigger" {...props} />
}

function DialogPortal({
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Portal>) {
  return <DialogPrimitive.Portal data-slot="dialog-portal" {...props} />
}

function DialogClose({
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Close>) {
  return <DialogPrimitive.Close data-slot="dialog-close" {...props} />
}

function DialogOverlay({
  className,
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Overlay>) {
  return (
    <DialogPrimitive.Overlay
      data-slot="dialog-overlay"
      className={cn(
        "fixed inset-0 z-50 bg-(--newspack-ui-color-overlay-50) duration-(--newspack-ui-duration-modal) ease-(--newspack-ui-ease-out) data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:animate-in data-[state=open]:fade-in-0 max-[599px]:duration-(--newspack-ui-duration-sheet)",
        className
      )}
      {...props}
    />
  )
}

function DialogContent({
  className,
  children,
  showCloseButton = true,
  container,
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Content> & {
  showCloseButton?: boolean
  container?: HTMLElement | null
}) {
  return (
    <DialogPortal data-slot="dialog-portal" container={container}>
      <DialogOverlay />
      <DialogPrimitive.Content
        data-slot="dialog-content"
        className={cn(
          "fixed top-[50%] left-[50%] z-50 flex max-h-[90%] w-full max-w-(--newspack-ui-width-m) translate-x-[-50%] translate-y-[-50%] flex-col overflow-auto overscroll-contain rounded-(--newspack-ui-radius-surface) bg-(--newspack-ui-color-surface) pb-(--newspack-ui-spacer-5) font-(family-name:--newspack-ui-font-family-body) text-(length:--newspack-ui-font-size-body) leading-(--newspack-ui-line-height-body) text-(color:--newspack-ui-color-text) duration-(--newspack-ui-duration-modal) outline-none [box-shadow:var(--nui-elevation-4)] ease-(--newspack-ui-ease-out) data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=closed]:zoom-out-96 data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=open]:zoom-in-96",
          "max-[599px]:top-auto max-[599px]:bottom-0 max-[599px]:left-0 max-[599px]:max-h-[calc(100%-var(--newspack-ui-spacer-5))] max-[599px]:max-w-full max-[599px]:translate-x-0 max-[599px]:translate-y-0 max-[599px]:rounded-t-(--newspack-ui-radius-sheet) max-[599px]:rounded-b-none max-[599px]:pb-[max(var(--newspack-ui-spacer-5),env(safe-area-inset-bottom))] max-[599px]:duration-(--newspack-ui-duration-sheet) max-[599px]:ease-(--newspack-ui-ease-drawer) max-[599px]:data-[state=closed]:slide-out-to-bottom max-[599px]:data-[state=closed]:zoom-out-100 max-[599px]:data-[state=open]:slide-in-from-bottom max-[599px]:data-[state=open]:zoom-in-100",
          className
        )}
        {...props}
      >
        {children}
        {showCloseButton && (
          <DialogPrimitive.Close asChild>
            <Button
              variant="ghost"
              size="icon"
              className="absolute end-[calc(var(--newspack-ui-spacer-5)-(var(--newspack-ui-spacer-8)-var(--newspack-ui-spacer-4))/2)] top-[calc(var(--newspack-ui-spacer-4)+(max(18px,var(--newspack-ui-font-size-title-m))*var(--newspack-ui-line-height-title-m)-var(--newspack-ui-spacer-8))/2)] rounded-(--newspack-ui-radius-pill) max-[599px]:top-[calc(var(--newspack-ui-spacer-5)+(max(18px,var(--newspack-ui-font-size-title-m))*var(--newspack-ui-line-height-title-m)-var(--newspack-ui-touch-target))/2)] max-[599px]:size-(--newspack-ui-touch-target) max-[599px]:end-[calc(var(--newspack-ui-spacer-5)-(var(--newspack-ui-touch-target)-var(--newspack-ui-spacer-4))/2)] [&_svg]:size-(--newspack-ui-spacer-4)"
            >
              <CloseIcon />
              <span className="sr-only">Close</span>
            </Button>
          </DialogPrimitive.Close>
        )}
      </DialogPrimitive.Content>
    </DialogPortal>
  )
}

function DialogHeader({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="dialog-header"
      className={cn(
        "flex flex-col px-(--newspack-ui-spacer-5) pt-(--newspack-ui-spacer-4) pe-[calc(var(--newspack-ui-spacer-5)+(var(--newspack-ui-spacer-8)+var(--newspack-ui-spacer-4))/2+var(--newspack-ui-spacer-3))] max-[599px]:pt-(--newspack-ui-spacer-5)",
        className
      )}
      {...props}
    />
  )
}

function DialogFooter({
  className,
  showCloseButton = false,
  children,
  ...props
}: React.ComponentProps<"div"> & {
  showCloseButton?: boolean
}) {
  return (
    <div
      data-slot="dialog-footer"
      className={cn(
        "mt-(--newspack-ui-spacer-5) flex flex-col gap-(--newspack-ui-spacer-2) px-(--newspack-ui-spacer-5) *:w-full",
        className
      )}
      {...props}
    >
      {children}
      {showCloseButton && (
        <DialogPrimitive.Close asChild>
          <Button variant="outline">Close</Button>
        </DialogPrimitive.Close>
      )}
    </div>
  )
}

function DialogTitle({
  className,
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Title>) {
  return (
    <DialogPrimitive.Title
      data-slot="dialog-title"
      className={cn(
        "font-(family-name:--newspack-ui-font-family-heading) text-[max(18px,var(--newspack-ui-font-size-title-m))] leading-(--newspack-ui-line-height-title-m) font-(weight:--newspack-ui-font-weight-strong) tracking-(--newspack-ui-letter-spacing-title-m)",
        className
      )}
      {...props}
    />
  )
}

function DialogDescription({
  className,
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Description>) {
  return (
    <DialogPrimitive.Description
      data-slot="dialog-description"
      className={cn("mt-(--newspack-ui-spacer-4) px-(--newspack-ui-spacer-5) [[data-slot=dialog-header]_&]:px-0", className)}
      {...props}
    />
  )
}

export {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogOverlay,
  DialogPortal,
  DialogTitle,
  DialogTrigger,
}
