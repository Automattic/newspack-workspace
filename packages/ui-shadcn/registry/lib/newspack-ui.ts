export const focusLayers =
	"outline-2 outline-transparent [--nui-focus:var(--newspack-ui-color-focus)] [box-shadow:var(--nui-outer,0_0_transparent),inset_0_0_0_var(--nui-rest-w,0px)_var(--nui-rest,transparent),inset_0_0_0_var(--nui-edge-w,0px)_var(--nui-edge,transparent),var(--nui-inner,0_0_transparent),0_0_0_var(--nui-halo-w,0px)_var(--newspack-ui-color-focus-halo)] focus-visible:[--nui-edge-w:var(--nui-focus-w,1px)] focus-visible:[--nui-edge:var(--nui-focus)] focus-visible:[--nui-halo-w:var(--newspack-ui-focus-halo-width)] focus-visible:transition-none"

export const field = [
	focusLayers,
	"w-full min-w-0 rounded-(--newspack-ui-field-radius) border border-(color:--nui-border) bg-(--newspack-ui-field-bg) text-(color:--newspack-ui-field-ink) font-(family-name:--newspack-ui-field-font-family) text-(length:--newspack-ui-font-size-body) leading-(--newspack-ui-line-height-body)",
	"[--nui-border:var(--newspack-ui-color-input-border)] [--nui-focus:var(--newspack-ui-color-input-border-focus)]",
	"transition-[background-color,border-color,box-shadow] duration-(--newspack-ui-duration-color) ease-[ease]",
	"hover:[--nui-border:var(--newspack-ui-color-input-border-hover)] focus-visible:[--nui-border:var(--nui-focus)]",
	"disabled:cursor-not-allowed disabled:border-(color:--newspack-ui-color-input-border-disabled) disabled:bg-(--newspack-ui-color-input-bg-disabled) disabled:text-(color:--newspack-ui-color-text-subtle) disabled:[-webkit-text-fill-color:currentcolor]",
	"aria-invalid:[--nui-border:var(--newspack-ui-color-error)] aria-invalid:[--nui-focus:var(--newspack-ui-color-error)] aria-invalid:[--nui-rest-w:1px] aria-invalid:[--nui-rest:var(--newspack-ui-color-error)] aria-invalid:focus-visible:[--nui-focus-w:2px]",
].join(" ")
