import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import type { Fulfilment, Sort } from "@/lib/queries";

const SORTS: { value: Sort; label: string }[] = [
  { value: "newest", label: "Newest listings" },
  { value: "freshest", label: "Freshest harvest" },
  { value: "price_asc", label: "Price: low to high" },
  { value: "price_desc", label: "Price: high to low" },
  { value: "nearest", label: "Nearest farms" },
];

const FULFILMENT: { value: Fulfilment | undefined; label: string }[] = [
  { value: undefined, label: "Pickup or delivery" },
  { value: "pickup", label: "I'll collect from the farm" },
  { value: "delivery", label: "Deliver to me" },
];

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  sort: Sort;
  fulfilment: Fulfilment | undefined;
  /** Sorting by distance needs a point to measure from. */
  canSortByDistance: boolean;
  onChange: (change: { sort?: Sort; fulfilment?: Fulfilment | undefined }) => void;
};

export function FilterDialog({ open, onOpenChange, sort, fulfilment, canSortByDistance, onChange }: Props) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="store-dialog">
        <DialogHeader>
          <DialogTitle>Sort and filter</DialogTitle>
          <DialogDescription>Changes apply straight away.</DialogDescription>
        </DialogHeader>

        <fieldset className="sort-options">
          <legend>Sort by</legend>
          {SORTS.filter((option) => option.value !== "nearest" || canSortByDistance).map((option) => (
            <label key={option.value}>
              <input type="radio" name="sort" checked={sort === option.value} onChange={() => onChange({ sort: option.value })} />
              {option.label}
            </label>
          ))}
        </fieldset>

        <fieldset className="sort-options">
          <legend>Getting it to you</legend>
          {FULFILMENT.map((option) => (
            <label key={option.label}>
              <input
                type="radio"
                name="fulfilment"
                checked={fulfilment === option.value}
                onChange={() => onChange({ fulfilment: option.value })}
              />
              {option.label}
            </label>
          ))}
        </fieldset>

        <Button onClick={() => onOpenChange(false)}>Show produce</Button>
      </DialogContent>
    </Dialog>
  );
}
