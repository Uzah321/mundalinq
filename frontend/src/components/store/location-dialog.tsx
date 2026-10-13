import { useState, type ReactNode } from "react";
import { Check, House, LoaderCircle, MapPin, Navigation } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { PROVINCES, RADIUS_OPTIONS, useShoppingArea, type ShoppingArea } from "@/lib/preferences";
import { useAddresses } from "@/lib/queries";
import { cn } from "@/lib/utils";

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
};

export function LocationDialog({ open, onOpenChange }: Props) {
  const [area, setArea] = useShoppingArea();
  const { data: addresses } = useAddresses();
  const [radiusKm, setRadiusKm] = useState(area?.kind === "nearby" ? area.radiusKm : 50);
  const [locating, setLocating] = useState(false);
  const [locateError, setLocateError] = useState<string | null>(null);

  function choose(next: ShoppingArea | null) {
    setArea(next);
    onOpenChange(false);
  }

  function useCurrentLocation() {
    if (!("geolocation" in navigator)) {
      setLocateError("This browser can't share your location. Pick a province instead.");
      return;
    }

    setLocating(true);
    setLocateError(null);
    navigator.geolocation.getCurrentPosition(
      (position) => {
        setLocating(false);
        choose({ kind: "nearby", label: "Near you", lat: position.coords.latitude, lng: position.coords.longitude, radiusKm });
      },
      (error) => {
        setLocating(false);
        setLocateError(
          error.code === error.PERMISSION_DENIED
            ? "Location access is blocked. Allow it in your browser settings, or pick a province below."
            : "We couldn't find your location just now. Pick a province instead.",
        );
      },
      { timeout: 10_000, maximumAge: 5 * 60_000 },
    );
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="store-dialog location-dialog">
        <DialogHeader>
          <DialogTitle>Where are you shopping from?</DialogTitle>
          <DialogDescription>We'll show produce from farms in your area first.</DialogDescription>
        </DialogHeader>

        <section className="location-group" aria-labelledby="nearby-heading">
          <h3 id="nearby-heading">Farms near a place</h3>
          <div className="radius-chips" role="radiogroup" aria-label="How far to look">
            {RADIUS_OPTIONS.map((option) => (
              <button
                key={option}
                type="button"
                role="radio"
                aria-checked={radiusKm === option}
                className={cn("choice", radiusKm === option && "selected")}
                onClick={() => setRadiusKm(option)}
              >
                Within {option} km
              </button>
            ))}
          </div>
          <Button className="location-locate" onClick={useCurrentLocation} disabled={locating}>
            {locating ? <LoaderCircle className="animate-spin" /> : <Navigation />}
            Use my current location
          </Button>
          {locateError && <p className="field-error">{locateError}</p>}

          {addresses?.map((address) => (
            <LocationOption
              key={address.id}
              icon={<House />}
              title={address.label || address.address_line}
              detail={`${address.address_line}, ${address.district}`}
              selected={area?.kind === "nearby" && area.label === (address.label || address.address_line)}
              onClick={() =>
                choose({
                  kind: "nearby",
                  label: address.label || address.address_line,
                  lat: Number(address.latitude),
                  lng: Number(address.longitude),
                  radiusKm,
                })
              }
            />
          ))}
        </section>

        <section className="location-group" aria-labelledby="province-heading">
          <h3 id="province-heading">Or a whole province</h3>
          <div className="province-grid">
            {PROVINCES.map((province) => (
              <button
                key={province}
                type="button"
                className={cn("choice", area?.kind === "province" && area.province === province && "selected")}
                onClick={() => choose({ kind: "province", province })}
              >
                {province}
              </button>
            ))}
          </div>
        </section>

        <LocationOption
          icon={<MapPin />}
          title="All of Zimbabwe"
          detail="Show every farm on MundaLinq"
          selected={area === null}
          onClick={() => choose(null)}
        />
      </DialogContent>
    </Dialog>
  );
}

function LocationOption({
  icon,
  title,
  detail,
  selected,
  onClick,
}: {
  icon: ReactNode;
  title: string;
  detail: string;
  selected: boolean;
  onClick: () => void;
}) {
  return (
    <button type="button" className={cn("location-option", selected && "selected")} onClick={onClick} aria-pressed={selected}>
      {icon}
      <span>
        <strong>{title}</strong>
        <small>{detail}</small>
      </span>
      {selected && <Check className="location-check" />}
    </button>
  );
}
