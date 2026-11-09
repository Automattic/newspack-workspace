export const MIN_HIDDEN_TO_COLLAPSE = 2;

/**
 * Whether a reveal toggle is worth showing: it is not for a single hidden item,
 * which reads better inline than behind "Show 1 more".
 *
 * @param hiddenCount How many items would be hidden.
 */
export const worthCollapsing = ( hiddenCount: number ): boolean => hiddenCount >= MIN_HIDDEN_TO_COLLAPSE;
