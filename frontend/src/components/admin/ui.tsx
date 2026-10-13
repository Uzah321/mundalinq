import { useEffect, useState, type FormEvent, type ReactNode } from "react";
import type { InfiniteData } from "@tanstack/react-query";
import { LoaderCircle, Search, ShieldCheck, TriangleAlert, type LucideIcon } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Label } from "@/components/ui/label";
import { ApiError, errorMessage } from "@/lib/api";
import type { Paginated } from "@/lib/types";
import { cn } from "@/lib/utils";

// Building blocks shared by the staff pages.

export function PageHeading({ title, text, children }: { title: string; text?: ReactNode; children?: ReactNode }) {
  return (
    <div className="admin-heading">
      <div className="admin-heading-row">
        <h1>{title}</h1>
        {children}
      </div>
      {text && <p>{text}</p>}
    </div>
  );
}

export type TabOption<T extends string> = { value: T; label: string; count?: number };

export function FilterTabs<T extends string>({ options, value, onSelect, label = "Show" }: { options: readonly TabOption<T>[]; value: T; onSelect: (value: T) => void; label?: string }) {
  return (
    <div className="admin-tabs" role="tablist" aria-label={label}>
      {options.map((option) => (
        <button
          key={option.value}
          type="button"
          role="tab"
          aria-selected={value === option.value}
          className={cn("choice", value === option.value && "selected")}
          onClick={() => onSelect(option.value)}
        >
          {option.label}
          {!!option.count && <span className="admin-count">{option.count}</span>}
        </button>
      ))}
    </div>
  );
}

/** Search as you type, sent once typing pauses. */
export function SearchBox({ value, onChange, placeholder }: { value: string; onChange: (value: string) => void; placeholder: string }) {
  const [text, setText] = useState(value);

  useEffect(() => {
    if (text.trim() === value) return;
    const timer = window.setTimeout(() => onChange(text.trim()), 350);
    return () => window.clearTimeout(timer);
  }, [text, value, onChange]);

  return (
    <label className="search-field admin-search">
      <Search />
      <input value={text} onChange={(event) => setText(event.target.value)} placeholder={placeholder} aria-label={placeholder} />
    </label>
  );
}

export const rowsOf = <T,>(data: InfiniteData<Paginated<T>> | undefined) => data?.pages.flatMap((page) => page.data) ?? [];

export type ListQuery = {
  isPending: boolean;
  isError: boolean;
  error: unknown;
  refetch: () => unknown;
  hasNextPage: boolean;
  isFetchingNextPage: boolean;
  fetchNextPage: () => unknown;
};

/** Loading, failure, empty and "show more" states around a list of rows. */
export function QueueList({ query, count, empty, children }: { query: ListQuery; count: number; empty: { title: string; text: string }; children: ReactNode }) {
  if (query.isPending) {
    return (
      <ul className="admin-list" aria-busy>
        {Array.from({ length: 3 }, (_, index) => (
          <li key={index} className="admin-row">
            <span className="admin-row-icon skeleton" />
            <span className="admin-row-main">
              <span className="skeleton skeleton-line short" />
              <span className="skeleton skeleton-line" />
            </span>
          </li>
        ))}
      </ul>
    );
  }

  if (query.isError) {
    return (
      <div className="empty-state admin-empty">
        <TriangleAlert />
        <h3>The list didn't load</h3>
        <p>{errorMessage(query.error)}</p>
        <Button onClick={() => query.refetch()}>Try again</Button>
      </div>
    );
  }

  if (count === 0) {
    return (
      <div className="empty-state admin-empty">
        <ShieldCheck />
        <h3>{empty.title}</h3>
        <p>{empty.text}</p>
      </div>
    );
  }

  return (
    <>
      <ul className="admin-list">{children}</ul>
      {query.hasNextPage && (
        <div className="load-more">
          <Button variant="outline" onClick={() => query.fetchNextPage()} disabled={query.isFetchingNextPage}>
            Show more
          </Button>
        </div>
      )}
    </>
  );
}

/** Loading and failure states for a single record's page. */
export function ItemState({ query, missing }: { query: { isPending: boolean; error: unknown; refetch: () => unknown }; missing: string }) {
  if (query.isPending) {
    return (
      <div className="admin-loading" aria-busy>
        <LoaderCircle className="animate-spin" />
      </div>
    );
  }
  const notFound = query.error instanceof ApiError && query.error.status === 404;
  return (
    <div className="empty-state admin-empty">
      <TriangleAlert />
      <h3>{notFound ? missing : "This page didn't load"}</h3>
      <p>{notFound ? "It may have been removed." : errorMessage(query.error)}</p>
      {!notFound && <Button onClick={() => query.refetch()}>Try again</Button>}
    </div>
  );
}

export function Facts({ children }: { children: ReactNode }) {
  return <dl className="admin-facts">{children}</dl>;
}

export function Fact({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div>
      <dt>{label}</dt>
      <dd>{children}</dd>
    </div>
  );
}

export type Tone = "wait" | "good" | "bad" | "plain";

export function Pill({ tone = "plain", icon: Icon, children }: { tone?: Tone; icon?: LucideIcon; children: ReactNode }) {
  return (
    <span className={cn("status-pill", tone)}>
      {Icon && <Icon />}
      {children}
    </span>
  );
}

export function Section({ title, wide, children, actions }: { title: string; wide?: boolean; children: ReactNode; actions?: ReactNode }) {
  return (
    <section className={cn("admin-card", wide && "wide")}>
      <div className="admin-card-head">
        <h2>{title}</h2>
        {actions}
      </div>
      {children}
    </section>
  );
}

export function AdminField({ id, label, hint, error, children }: { id: string; label: string; hint?: string; error?: string; children: ReactNode }) {
  return (
    <div className="form-field">
      <Label htmlFor={id} className="field-label">
        {label}
      </Label>
      {children}
      {error ? <small className="field-error">{error}</small> : hint && <small className="field-hint">{hint}</small>}
    </div>
  );
}

/** Pending and error state for a dialog's submit; the error's field messages go under their inputs. */
export function useSubmit() {
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<unknown>(null);

  return {
    pending,
    error,
    fieldError: (name: string) => (error instanceof ApiError ? error.field(name) : undefined),
    /** The message for errors that belong to no field. */
    generalError: error && !(error instanceof ApiError && Object.keys(error.fieldErrors).length) ? errorMessage(error) : null,
    reset: () => setError(null),
    async run(action: () => Promise<unknown>): Promise<boolean> {
      setPending(true);
      setError(null);
      try {
        await action();
        return true;
      } catch (caught) {
        setError(caught);
        return false;
      } finally {
        setPending(false);
      }
    },
  };
}

type ConfirmProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  description: ReactNode;
  confirmLabel: string;
  destructive?: boolean;
  /** Runs the action; the dialog closes when it succeeds and shows the error when it fails. */
  onConfirm: () => Promise<unknown>;
};

export function ConfirmDialog({ open, onOpenChange, title, description, confirmLabel, destructive, onConfirm }: ConfirmProps) {
  const submit = useSubmit();

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (!next) submit.reset();
        onOpenChange(next);
      }}
    >
      <DialogContent className="store-dialog">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{description}</DialogDescription>
        </DialogHeader>
        {submit.error != null && <p className="field-error">{errorMessage(submit.error)}</p>}
        <div className="dialog-actions">
          <Button variant="ghost" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button
            variant={destructive ? "destructive" : "default"}
            disabled={submit.pending}
            onClick={async () => {
              if (await submit.run(onConfirm)) onOpenChange(false);
            }}
          >
            {submit.pending && <LoaderCircle className="animate-spin" />}
            {confirmLabel}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  );
}

type ReasonProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  description: ReactNode;
  label: string;
  submitLabel: string;
  destructive?: boolean;
  /** Ready-made reasons; tapping one fills the box. */
  quickReasons?: readonly string[];
  /** The API's name for the field, so its validation message shows under the box. */
  field?: string;
  minLength?: number;
  maxLength?: number;
  /** Shown when the box is (nearly) empty; defaults to asking for a reason. */
  tooShort?: string;
  onSubmit: (reason: string) => Promise<unknown>;
};

/** A decision that needs a written reason (reject, suspend, hide…), usually sent on to the person affected. */
export function ReasonDialog({ open, onOpenChange, title, description, label, submitLabel, destructive, quickReasons, field = "reason", minLength = 5, maxLength = 300, tooShort, onSubmit }: ReasonProps) {
  const [reason, setReason] = useState("");
  const [problem, setProblem] = useState<string | null>(null);
  const submit = useSubmit();
  const fieldError = problem ?? submit.fieldError(field);

  async function send(event: FormEvent) {
    event.preventDefault();
    const text = reason.trim();
    if (text.length < minLength) {
      setProblem(tooShort ?? (quickReasons?.length ? "Write a short reason, or pick one of the reasons above." : "Write a short reason."));
      return;
    }
    setProblem(null);
    if (await submit.run(() => onSubmit(text))) {
      setReason("");
      onOpenChange(false);
    }
  }

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (!next) {
          submit.reset();
          setProblem(null);
        }
        onOpenChange(next);
      }}
    >
      <DialogContent className="store-dialog">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{description}</DialogDescription>
        </DialogHeader>
        <form className="form-stack" onSubmit={send} noValidate>
          {!!quickReasons?.length && (
            <div className="reason-chips" role="group" aria-label="Common reasons">
              {quickReasons.map((text) => (
                <button
                  key={text}
                  type="button"
                  className={cn("choice", reason === text && "selected")}
                  onClick={() => {
                    setReason(text);
                    setProblem(null);
                  }}
                >
                  {text}
                </button>
              ))}
            </div>
          )}
          <AdminField id="admin-reason" label={label} error={fieldError} hint={`${reason.length}/${maxLength} characters`}>
            <textarea
              id="admin-reason"
              className="admin-textarea"
              maxLength={maxLength}
              value={reason}
              aria-invalid={!!fieldError}
              onChange={(event) => {
                setReason(event.target.value);
                setProblem(null);
              }}
            />
          </AdminField>
          {submit.generalError && <p className="field-error">{submit.generalError}</p>}
          <div className="dialog-actions">
            <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" variant={destructive ? "destructive" : "default"} disabled={submit.pending}>
              {submit.pending && <LoaderCircle className="animate-spin" />}
              {submitLabel}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  );
}
