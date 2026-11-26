import * as React from "react"
import { cn } from "@/lib/utils"
import { field } from "@/lib/newspack-ui"

function Input({ className, type, ...props }: React.ComponentProps<"input">) {
  return (
    <input
      type={type}
      data-slot="input"
      className={cn(
        field,
        "block min-h-(--newspack-ui-spacer-9) px-(--newspack-ui-control-padding-inline) py-[calc(var(--newspack-ui-spacer-2)-1px)] placeholder:text-(color:--newspack-ui-color-text-subtle) placeholder:opacity-100",
        className
      )}
      {...props}
    />
  )
}

export { Input }
