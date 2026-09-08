import * as React from "react"

import { cn } from "@/lib/utils"

function Input({ className, type, ...props }: React.ComponentProps<"input">) {
  return (
    <input
      type={type}
      data-slot="input"
      className={cn(
        "border-input file:text-foreground placeholder:text-muted-foreground selection:bg-primary selection:text-primary-foreground flex h-[38px] w-full min-w-0 rounded-lg border bg-white px-3 py-1 text-[14px] text-foreground transition-[color,box-shadow,border-color] outline-none file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-60",
        "focus-visible:border-primary focus-visible:ring-[3px] focus-visible:ring-primary/18",
        "aria-invalid:border-danger aria-invalid:ring-danger/15",
        className
      )}
      {...props}
    />
  )
}

export { Input }
