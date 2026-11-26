import * as React from "react"

function Icon({
  children,
  ...props
}: React.ComponentProps<"svg">) {
  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      width="24"
      height="24"
      viewBox="0 0 24 24"
      aria-hidden="true"
      focusable="false"
      fill="currentColor"
      {...props}
    >
      {children}
    </svg>
  )
}

export function InfoIcon(props: React.ComponentProps<"svg">) {
  return (
    <Icon {...props}>
      <path
        fillRule="evenodd"
        clipRule="evenodd"
        d="M5.5 12a6.5 6.5 0 1 0 13 0 6.5 6.5 0 0 0-13 0ZM12 4a8 8 0 1 0 0 16 8 8 0 0 0 0-16Zm.75 4v1.5h-1.5V8h1.5Zm0 8v-5h-1.5v5h1.5Z"
      />
    </Icon>
  )
}

export function SuccessIcon(props: React.ComponentProps<"svg">) {
  return (
    <Icon {...props}>
      <path
        fillRule="evenodd"
        clipRule="evenodd"
        d="M12 18.5a6.5 6.5 0 1 1 0-13 6.5 6.5 0 0 1 0 13ZM4 12a8 8 0 1 1 16 0 8 8 0 0 1-16 0Zm11.53-1.47-1.06-1.06L11 12.94l-1.47-1.47-1.06 1.06L11 15.06l4.53-4.53Z"
      />
    </Icon>
  )
}

export function WarningIcon(props: React.ComponentProps<"svg">) {
  return (
    <Icon {...props}>
      <path
        fillRule="evenodd"
        clipRule="evenodd"
        d="M12.218 5.377a.25.25 0 0 0-.436 0l-7.29 12.96a.25.25 0 0 0 .218.373h14.58a.25.25 0 0 0 .218-.372l-7.29-12.96Zm-1.743-.735c.669-1.19 2.381-1.19 3.05 0l7.29 12.96a1.75 1.75 0 0 1-1.525 2.608H4.71a1.75 1.75 0 0 1-1.525-2.608l7.29-12.96ZM12.75 17.46h-1.5v-1.5h1.5v1.5Zm-1.5-3h1.5v-5h-1.5v5Z"
      />
    </Icon>
  )
}

export function ErrorIcon(props: React.ComponentProps<"svg">) {
  return (
    <Icon {...props}>
      <path
        fillRule="evenodd"
        clipRule="evenodd"
        d="M5.5 12a6.5 6.5 0 1 0 13 0 6.5 6.5 0 0 0-13 0ZM12 4a8 8 0 1 0 0 16 8 8 0 0 0 0-16Zm-.75 12v-1.5h1.5V16h-1.5Zm0-8v5h1.5V8h-1.5Z"
      />
    </Icon>
  )
}

export function CloseIcon(props: React.ComponentProps<"svg">) {
  return (
    <Icon {...props}>
      <path d="m13.06 12 6.47-6.47-1.06-1.06L12 10.94 5.53 4.47 4.47 5.53 10.94 12l-6.47 6.47 1.06 1.06L12 13.06l6.47 6.47 1.06-1.06L13.06 12Z" />
    </Icon>
  )
}
