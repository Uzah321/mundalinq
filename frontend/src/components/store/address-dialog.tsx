import { useState, type FormEvent, type ReactNode } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { Crosshair, LoaderCircle } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { api, ApiError, errorMessage } from "@/lib/api";
import { meKey, useSession } from "@/lib/auth";
import { PROVINCES } from "@/lib/preferences";
import type { Address } from "@/lib/types";
import { cn } from "@/lib/utils";

/** Each province's main town: the delivery pin when the browser can't share a location. */
const PROVINCE_CENTRES: Record<(typeof PROVINCES)[number], [number, number]> = {
  Bulawayo: [-20.15, 28.58],
  Harare: [-17.83, 31.05],
  Manicaland: [-18.97, 32.67],
  "Mashonaland Central": [-17.3, 31.33],
  "Mashonaland East": [-18.19, 31.55],
  "Mashonaland West": [-17.36, 30.2],
  Masvingo: [-20.07, 30.83],
  "Matabeleland North": [-18.93, 27.8],
  "Matabeleland South": [-20.94, 29.01],
  Midlands: [-19.45, 29.82],
};

const LABELS = ["Home", "Work", "Other"] as const;

type Props = { open: boolean; onOpenChange: (open: boolean) => void; onSaved: (address: Address) => void };

/** A delivery address. Farms deliver within a distance, so it needs a map point as well as the address. */
export function AddressDialog({ open, onOpenChange, onSaved }: Props) {
  const { user } = useSession();
  const queryClient = useQueryClient();
  const [form, setForm] = useState({
    label: "Home",
    recipient_name: user?.name ?? "",
    phone: user?.phone ?? "",
    province: "Harare",
    district: "",
    address_line: "",
    directions: "",
  });
  const [point, setPoint] = useState<{ lat: number; lng: number } | null>(null);
  const [locating, setLocating] = useState(false);
  const [locateNote, setLocateNote] = useState<string | null>(null);
  const [problems, setProblems] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const set = (field: keyof typeof form) => (event: { target: { value: string } }) => setForm({ ...form, [field]: event.target.value });
  const fieldError = (field: string) => problems[field] ?? (error instanceof ApiError ? error.field(field) : undefined);

  function locate() {
    if (!navigator.geolocation) {
      setLocateNote("This browser can't share your location; we'll use the province instead.");
      return;
    }
    setLocating(true);
    navigator.geolocation.getCurrentPosition(
      (position) => {
        setPoint({ lat: position.coords.latitude, lng: position.coords.longitude });
        setLocateNote("Pinned to where you are now.");
        setLocating(false);
      },
      () => {
        setLocateNote("We couldn't get your location; we'll use the province instead. The farm will call for directions.");
        setLocating(false);
      },
      { timeout: 10_000 },
    );
  }

  async function save(event: FormEvent) {
    event.preventDefault();
    const found: Record<string, string> = {};
    if (!form.district.trim()) found.district = "Enter the town or district, e.g. Borrowdale.";
    if (!form.address_line.trim()) found.address_line = "Enter the street address or house number.";
    if (!form.recipient_name.trim()) found.recipient_name = "Who should the farm ask for?";
    if (!form.phone.trim()) found.phone = "A number the farm can call on arrival.";
    setProblems(found);
    if (Object.keys(found).length) return;

    const [lat, lng] = point ? [point.lat, point.lng] : PROVINCE_CENTRES[form.province as keyof typeof PROVINCE_CENTRES];
    setSaving(true);
    setError(null);
    try {
      const response = await api<{ data: Address }>("/me/addresses", {
        method: "POST",
        body: {
          ...form,
          district: form.district.trim(),
          address_line: form.address_line.trim(),
          directions: form.directions.trim() || null,
          latitude: lat,
          longitude: lng,
        },
      });
      await queryClient.invalidateQueries({ queryKey: [...meKey, "addresses"] });
      onSaved(response.data);
      onOpenChange(false);
    } catch (caught) {
      setError(caught);
    } finally {
      setSaving(false);
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="store-dialog">
        <DialogHeader>
          <DialogTitle>Add a delivery address</DialogTitle>
          <DialogDescription>Where the farm should bring your order.</DialogDescription>
        </DialogHeader>
        <form className="form-stack" onSubmit={save} noValidate>
          <div className="choice-row three" role="radiogroup" aria-label="Label">
            {LABELS.map((label) => (
              <button key={label} type="button" role="radio" aria-checked={form.label === label} className={cn("choice", form.label === label && "selected")} onClick={() => setForm({ ...form, label })}>
                {label}
              </button>
            ))}
          </div>
          <Field id="address-line" label="Street address" error={fieldError("address_line")}>
            <Input id="address-line" value={form.address_line} onChange={set("address_line")} placeholder="e.g. 12 Samora Machel Avenue" />
          </Field>
          <div className="form-pair">
            <Field id="address-district" label="Town or district" error={fieldError("district")}>
              <Input id="address-district" value={form.district} onChange={set("district")} placeholder="e.g. Borrowdale" />
            </Field>
            <Field id="address-province" label="Province" error={fieldError("province")}>
              <select id="address-province" className="admin-select" value={form.province} onChange={set("province")}>
                {PROVINCES.map((province) => (
                  <option key={province}>{province}</option>
                ))}
              </select>
            </Field>
          </div>
          <Field id="address-directions" label="Directions (optional)" error={fieldError("directions")}>
            <Input id="address-directions" value={form.directions} onChange={set("directions")} placeholder="e.g. Blue gate opposite the school" />
          </Field>
          <div className="form-pair">
            <Field id="address-name" label="Who receives it" error={fieldError("recipient_name")}>
              <Input id="address-name" value={form.recipient_name} onChange={set("recipient_name")} />
            </Field>
            <Field id="address-phone" label="Their phone" error={fieldError("phone")}>
              <Input id="address-phone" type="tel" value={form.phone} onChange={set("phone")} placeholder="0771 234 567" />
            </Field>
          </div>
          <div className="locate-row">
            <Button type="button" variant="outline" onClick={locate} disabled={locating}>
              {locating ? <LoaderCircle className="animate-spin" /> : <Crosshair />} Use my current location
            </Button>
            <small className="field-hint">{locateNote ?? "Helps the farm find you. Without it we use the province."}</small>
          </div>
          {error != null && !(error instanceof ApiError && Object.keys(error.fieldErrors).length) && <p className="field-error">{errorMessage(error)}</p>}
          <div className="dialog-actions">
            <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" disabled={saving}>
              {saving && <LoaderCircle className="animate-spin" />}
              Save address
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function Field({ id, label, error, children }: { id: string; label: string; error?: string; children: ReactNode }) {
  return (
    <div className="form-field">
      <Label htmlFor={id} className="field-label">
        {label}
      </Label>
      {children}
      {error && <small className="field-error">{error}</small>}
    </div>
  );
}
