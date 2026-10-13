import { useState, type FormEvent } from "react";
import { createFileRoute, useNavigate } from "@tanstack/react-router";
import { LoaderCircle } from "lucide-react";
import { toast } from "sonner";
import { AdminField } from "@/components/admin/ui";
import { ShopPage } from "@/components/store/shop-page";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { ApiError, errorMessage } from "@/lib/api";
import { PROVINCES } from "@/lib/preferences";
import { useCategories, useUnits } from "@/lib/queries";
import { FREQUENCIES, useQuoteActions, type NewQuoteRequest, type QuoteFulfilment } from "@/lib/quotes";
import { cn } from "@/lib/utils";

export const Route = createFileRoute("/quotes/new")({
  component: NewQuote,
});

function NewQuote() {
  return (
    <ShopPage title="Ask farms for a price" back={{ to: "/quotes", label: "Requests for quotes" }} signInReason="Sign in to ask farms for a price.">
      <QuoteForm />
    </ShopPage>
  );
}

const SHOWN_FIELDS = ["title", "category_id", "quantity", "needed_by", "province", "district", "notes"];

const inThreeDays = () => {
  const date = new Date();
  date.setDate(date.getDate() + 3);
  return date.toISOString().slice(0, 10);
};

function QuoteForm() {
  const navigate = useNavigate();
  const categories = useCategories();
  const units = useUnits();
  const { create } = useQuoteActions();
  const [form, setForm] = useState<NewQuoteRequest>({
    category_id: "",
    title: "",
    quantity: "",
    unit: "kg",
    frequency: "weekly",
    needed_by: inThreeDays(),
    fulfilment: "either",
    province: "Harare",
    district: "",
    notes: "",
    open_days: 7,
  });
  const [problems, setProblems] = useState<Record<string, string>>({});
  const error = create.error instanceof ApiError ? create.error : null;
  const fieldError = (field: string) => problems[field] ?? error?.field(field);
  // Once a field changes, its old complaint goes away.
  const clear = (field: string) => {
    setProblems((current) => (field in current ? Object.fromEntries(Object.entries(current).filter(([key]) => key !== field)) : current));
    if (error?.field(field)) create.reset();
  };
  // Problems with a field shown on the form appear under it; anything else (a closed category, a blocked account) below.
  const formError = create.error != null && !SHOWN_FIELDS.some((field) => error?.field(field)) ? errorMessage(create.error) : null;
  const set = (field: keyof NewQuoteRequest) => (event: { target: { value: string } }) => {
    setForm({ ...form, [field]: event.target.value });
    clear(field);
  };

  async function submit(event: FormEvent) {
    event.preventDefault();
    const found: Record<string, string> = {};
    if (!form.category_id) found.category_id = "Choose what kind of produce it is.";
    if (form.title.trim().length < 3) found.title = "Say what you need, e.g. Roma tomatoes.";
    if (!(Number(form.quantity) > 0)) found.quantity = "Enter how much, e.g. 200.";
    if (!form.district.trim()) found.district = "Enter your town or district.";
    setProblems(found);
    if (Object.keys(found).length) return;

    try {
      const { request, farmsTold } = await create.mutateAsync({ ...form, title: form.title.trim(), district: form.district.trim(), notes: form.notes?.trim() || undefined });
      if (farmsTold > 0) {
        toast.success("Request sent", {
          description: `${farmsTold} farm${farmsTold === 1 ? "" : "s"} in ${request.province} that sell${farmsTold === 1 ? "s" : ""} this ${farmsTold === 1 ? "has" : "have"} been told. Offers show up here.`,
        });
      } else {
        toast("Request posted", {
          description: `No farm in ${request.province} sells this on MundaLinq yet. It stays open, so farms that start selling it there can still answer.`,
        });
      }
      navigate({ to: "/quotes/$requestId", params: { requestId: request.id } });
    } catch {
      // Shown under the fields or below the form.
    }
  }

  return (
    <form className="shop-card form-stack quote-form" onSubmit={submit} noValidate>
      <div className="form-pair">
        <AdminField id="quote-title" label="What do you need?" error={fieldError("title")}>
          <Input id="quote-title" value={form.title} onChange={set("title")} placeholder="e.g. Roma tomatoes" maxLength={120} />
        </AdminField>
        <AdminField id="quote-category" label="Kind of produce" error={fieldError("category_id")}>
          <select id="quote-category" className="admin-select" value={form.category_id} onChange={set("category_id")}>
            <option value="">Choose…</option>
            {categories.data?.map((parent) => (
              <optgroup key={parent.id} label={parent.name}>
                <option value={parent.id}>{parent.name} (any)</option>
                {parent.children?.map((child) => (
                  <option key={child.id} value={child.id}>
                    {child.name}
                  </option>
                ))}
              </optgroup>
            ))}
          </select>
        </AdminField>
      </div>

      <div className="form-pair">
        <AdminField id="quote-quantity" label="How much" error={fieldError("quantity")}>
          <div className="pay-row">
            <Input id="quote-quantity" inputMode="decimal" value={form.quantity} onChange={(event) => {
                setForm({ ...form, quantity: event.target.value.replace(/[^0-9.]/g, "") });
                clear("quantity");
              }} placeholder="200" />
            <select className="admin-select" value={form.unit} onChange={set("unit")} aria-label="Unit">
              {[...(units.data?.values() ?? [])].map((unit) => (
                <option key={unit.code} value={unit.code}>
                  {unit.plural_name}
                </option>
              ))}
            </select>
          </div>
        </AdminField>
        <AdminField id="quote-date" label="First needed by" error={fieldError("needed_by")}>
          <Input id="quote-date" type="date" value={form.needed_by} min={new Date().toISOString().slice(0, 10)} onChange={set("needed_by")} />
        </AdminField>
      </div>

      <div className="form-field">
        <span className="field-label">How often</span>
        <div className="choice-row four" role="radiogroup" aria-label="How often">
          {FREQUENCIES.map((option) => (
            <button key={option.value} type="button" role="radio" aria-checked={form.frequency === option.value} className={cn("choice", form.frequency === option.value && "selected")} onClick={() => setForm({ ...form, frequency: option.value })}>
              {option.label}
            </button>
          ))}
        </div>
      </div>

      <div className="form-field">
        <span className="field-label">Getting it</span>
        <div className="choice-row three" role="radiogroup" aria-label="Getting it">
          {(
            [
              ["either", "Either is fine"],
              ["delivery", "Delivered to me"],
              ["pickup", "I'll collect"],
            ] as [QuoteFulfilment, string][]
          ).map(([value, label]) => (
            <button key={value} type="button" role="radio" aria-checked={form.fulfilment === value} className={cn("choice", form.fulfilment === value && "selected")} onClick={() => setForm({ ...form, fulfilment: value })}>
              {label}
            </button>
          ))}
        </div>
      </div>

      <div className="form-pair">
        <AdminField id="quote-province" label="Province" error={fieldError("province")}>
          <select id="quote-province" className="admin-select" value={form.province} onChange={set("province")}>
            {PROVINCES.map((province) => (
              <option key={province}>{province}</option>
            ))}
          </select>
        </AdminField>
        <AdminField id="quote-district" label="Town or district" error={fieldError("district")}>
          <Input id="quote-district" value={form.district} onChange={set("district")} placeholder="e.g. Borrowdale" maxLength={80} />
        </AdminField>
      </div>

      <AdminField id="quote-notes" label="Anything else farms should know (optional)" error={fieldError("notes")}>
        <textarea id="quote-notes" className="admin-textarea" maxLength={1000} value={form.notes} onChange={set("notes")} placeholder="e.g. Firm and red, for a restaurant. Delivery before 8am." />
      </AdminField>

      <AdminField id="quote-days" label="Take offers for">
        <select id="quote-days" className="admin-select" value={form.open_days} onChange={(event) => setForm({ ...form, open_days: Number(event.target.value) })}>
          {[3, 7, 14].map((days) => (
            <option key={days} value={days}>
              {days} days
            </option>
          ))}
        </select>
      </AdminField>

      {formError && <p className="field-error">{formError}</p>}
      <Button type="submit" className="form-submit" disabled={create.isPending}>
        {create.isPending && <LoaderCircle className="animate-spin" />}
        Send to farms
      </Button>
      <p className="field-hint">Only farms in that province that sell this produce see your request, with your first name only.</p>
    </form>
  );
}
