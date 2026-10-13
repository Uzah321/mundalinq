import { useState, type FormEvent, type ReactNode } from "react";
import { createFileRoute, Link, useNavigate } from "@tanstack/react-router";
import { ArrowLeft, BadgeCheck, Clock, ExternalLink, FileText, LoaderCircle, MapPin, RotateCw, ShieldX, TriangleAlert, X } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Label } from "@/components/ui/label";
import {
  documentTypes,
  farmerTypes,
  idDocumentTypes,
  useApproveVerification,
  useDocumentUrl,
  useRejectVerification,
  useVerification,
  type ReviewStatus,
} from "@/lib/admin";
import { ApiError, errorMessage } from "@/lib/api";
import { formatDateTime, formatPhone, timeAgo } from "@/lib/format";
import type { FarmLocation, FarmVerification, VerificationDocument } from "@/lib/types";
import { cn } from "@/lib/utils";

export const Route = createFileRoute("/admin/verifications/$verificationId")({
  component: VerificationReview,
});

// The farmer receives the reason as a message, so these are written to them.
const QUICK_REASONS = [
  "The ID photo is blurry or cut off. Please send a clear photo of the whole document.",
  "The name on the ID doesn't match the name on your account.",
  "The ID number you typed doesn't match the one on the document.",
  "The registration certificate is missing or can't be read.",
];

function VerificationReview() {
  const { verificationId } = Route.useParams();
  const query = useVerification(verificationId);
  const [decision, setDecision] = useState<"approve" | "reject" | null>(null);

  if (query.isPending) {
    return (
      <div className="admin-loading" aria-busy>
        <LoaderCircle className="animate-spin" />
      </div>
    );
  }

  if (query.isError) {
    const missing = query.error instanceof ApiError && query.error.status === 404;
    return (
      <div className="empty-state admin-empty">
        <TriangleAlert />
        <h3>{missing ? "This submission doesn't exist" : "This submission didn't load"}</h3>
        <p>{missing ? "Go back to the list to see what's waiting." : errorMessage(query.error)}</p>
        <div className="empty-actions">
          {!missing && <Button onClick={() => query.refetch()}>Try again</Button>}
          <Button variant="outline" asChild>
            <Link to="/admin/verifications">All verifications</Link>
          </Button>
        </div>
      </div>
    );
  }

  const verification = query.data;
  const { farm, submitted_by: submitter } = verification;
  const documents = verification.documents ?? [];
  const locations = farm?.locations ?? (farm?.primary_location ? [farm.primary_location] : []);
  const close = (open: boolean) => !open && setDecision(null);

  return (
    <>
      <Link to="/admin/verifications" className="admin-back">
        <ArrowLeft /> All verifications
      </Link>

      <div className="admin-heading">
        <StatusPill status={verification.status} />
        <h1>{farm?.name ?? "Farm"}</h1>
        <p>
          {farm && `${farmerTypes[farm.farmer_type]} · `}
          {verification.submitted_at && `Sent ${timeAgo(verification.submitted_at)} (${formatDateTime(verification.submitted_at)})`}
        </p>
      </div>

      {verification.status !== "pending" && <Outcome verification={verification} />}

      <div className="admin-grid">
        <section className="admin-card wide">
          <h2>Documents</h2>
          {documents.length === 0 ? (
            <p className="admin-hint">No documents came with this submission.</p>
          ) : (
            <div className="doc-grid">
              {documents.map((document) => (
                <DocumentView key={document.id} verificationId={verification.id} document={document} />
              ))}
            </div>
          )}
        </section>

        <section className="admin-card">
          <h2>Identity</h2>
          <dl className="admin-facts">
            <Fact label="Document">{idDocumentTypes[verification.id_document_type]}</Fact>
            <Fact label={verification.id_document_type === "passport" ? "Passport number" : "ID number"}>
              <span className="admin-mono">{verification.id_number}</span>
            </Fact>
            {verification.business_registration_number && (
              <Fact label="Registration number">
                <span className="admin-mono">{verification.business_registration_number}</span>
              </Fact>
            )}
            {submitter && <Fact label="Name on the account">{submitter.name}</Fact>}
          </dl>
          {verification.notes && (
            <blockquote className="admin-note">
              <strong>From the farmer:</strong> {verification.notes}
            </blockquote>
          )}
          <p className="admin-hint">
            Check that the name and number on the document match these, and that the photo is clear and shows the whole document.
          </p>
        </section>

        {submitter && (
          <section className="admin-card">
            <h2>Sent by</h2>
            <dl className="admin-facts">
              <Fact label="Name">{submitter.name}</Fact>
              <Fact label="Phone">
                {formatPhone(submitter.phone)}{" "}
                {submitter.phone_verified ? (
                  <span className="verified">
                    <BadgeCheck /> confirmed
                  </span>
                ) : (
                  <span className="admin-warn">not confirmed</span>
                )}
              </Fact>
              {submitter.email && <Fact label="Email">{submitter.email}</Fact>}
              {!!submitter.roles?.length && <Fact label="Account">{submitter.roles.map(roleName).join(", ")}</Fact>}
              {submitter.created_at && <Fact label="Joined">{formatDateTime(submitter.created_at)}</Fact>}
            </dl>
          </section>
        )}

        {farm && (
          <section className="admin-card wide">
            <h2>Farm</h2>
            <dl className="admin-facts">
              <Fact label="Type">{farmerTypes[farm.farmer_type]}</Fact>
              {farm.contact_phone && <Fact label="Farm phone">{formatPhone(farm.contact_phone)}</Fact>}
              {farm.contact_email && <Fact label="Farm email">{farm.contact_email}</Fact>}
              <Fact label="Offers">
                {[
                  farm.offers_pickup && "Collection",
                  farm.offers_delivery && (farm.delivery_radius_km ? `Delivery within ${farm.delivery_radius_km} km` : "Delivery"),
                ]
                  .filter(Boolean)
                  .join(", ") || "Not set"}
              </Fact>
              {farm.created_at && <Fact label="Farm added">{formatDateTime(farm.created_at)}</Fact>}
            </dl>
            {farm.description && <p className="admin-description">{farm.description}</p>}
            {locations.length > 0 && (
              <div className="admin-places">
                {locations.map((location) => (
                  <Place key={location.id} location={location} />
                ))}
              </div>
            )}
          </section>
        )}
      </div>

      {verification.status === "pending" && (
        <div className="admin-decision">
          <Button variant="outline" onClick={() => setDecision("reject")}>
            <X /> Reject
          </Button>
          <Button onClick={() => setDecision("approve")}>
            <BadgeCheck /> Approve farm
          </Button>
        </div>
      )}

      <ApproveDialog verification={verification} open={decision === "approve"} onOpenChange={close} />
      <RejectDialog verification={verification} open={decision === "reject"} onOpenChange={close} />
    </>
  );
}

const PILLS: Record<ReviewStatus, [typeof Clock, string]> = {
  pending: [Clock, "Waiting for review"],
  approved: [BadgeCheck, "Approved"],
  rejected: [ShieldX, "Rejected"],
};

function StatusPill({ status }: { status: ReviewStatus }) {
  const [Icon, label] = PILLS[status];
  return (
    <span className={cn("status-pill", status)}>
      <Icon /> {label}
    </span>
  );
}

function Outcome({ verification }: { verification: FarmVerification }) {
  const approved = verification.status === "approved";
  const reviewer = verification.reviewed_by?.name;

  return (
    <div className={cn("admin-outcome", verification.status)}>
      {approved ? <BadgeCheck /> : <ShieldX />}
      <p>
        {approved ? "Approved" : "Rejected"}
        {reviewer && ` by ${reviewer}`}
        {verification.reviewed_at && ` on ${formatDateTime(verification.reviewed_at)}`}.
        {verification.rejection_reason && (
          <>
            <br />
            Reason sent to the farmer: “{verification.rejection_reason}”
          </>
        )}
      </p>
    </div>
  );
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div>
      <dt>{label}</dt>
      <dd>{children}</dd>
    </div>
  );
}

const roleName = (role: string) => role.charAt(0).toUpperCase() + role.slice(1).replaceAll("_", " ");

function formatSize(bytes: number): string {
  return bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function DocumentView({ verificationId, document }: { verificationId: string; document: VerificationDocument }) {
  const { url, error } = useDocumentUrl(verificationId, document.id);
  // Phone photos of IDs are often sideways; quarter turns, shrunk to stay inside the 4:3 frame.
  const [turns, setTurns] = useState(0);
  const image = document.mime_type.startsWith("image/");
  const label = documentTypes[document.type];

  return (
    <figure className="doc-card">
      <div className="doc-frame">
        {error ? (
          <p className="doc-message">
            <TriangleAlert /> {errorMessage(error)}
          </p>
        ) : !url ? (
          <div className="skeleton" aria-label="Loading document" />
        ) : image ? (
          <a href={url} target="_blank" rel="noreferrer" aria-label={`Open the ${label.toLowerCase()} full size`}>
            <img src={url} alt={label} style={{ transform: `rotate(${turns * 90}deg)${turns % 2 ? " scale(0.75)" : ""}` }} />
          </a>
        ) : (
          <a className="doc-message" href={url} target="_blank" rel="noreferrer">
            <FileText /> Open the PDF
          </a>
        )}
      </div>
      <figcaption>
        <span>
          <strong>{label}</strong>
          <small>
            {document.original_name} · {formatSize(document.size_bytes)}
          </small>
        </span>
        {url && (
          <span className="doc-tools">
            {image && (
              <Button variant="ghost" size="icon" onClick={() => setTurns((turns + 1) % 4)} aria-label="Rotate">
                <RotateCw />
              </Button>
            )}
            <Button variant="ghost" size="icon" asChild>
              <a href={url} target="_blank" rel="noreferrer" aria-label="Open full size">
                <ExternalLink />
              </a>
            </Button>
          </span>
        )}
      </figcaption>
    </figure>
  );
}

function Place({ location }: { location: FarmLocation }) {
  const map = `https://www.google.com/maps/search/?api=1&query=${location.latitude},${location.longitude}`;

  return (
    <div className="admin-place">
      <MapPin />
      <span>
        <strong>{location.name ?? (location.is_primary ? "Main location" : "Location")}</strong>
        <small>{[location.address_line, location.ward, location.district, location.province].filter(Boolean).join(", ")}</small>
      </span>
      <a href={map} target="_blank" rel="noreferrer">
        Open map <ExternalLink />
      </a>
    </div>
  );
}

type DecisionProps = { verification: FarmVerification; open: boolean; onOpenChange: (open: boolean) => void };

function ApproveDialog({ verification, open, onOpenChange }: DecisionProps) {
  const approve = useApproveVerification();
  const navigate = useNavigate();
  const farmName = verification.farm?.name ?? "this farm";

  async function confirm() {
    if (!(await approve.mutateAsync(verification.id).catch(() => null))) return;
    toast.success(`${farmName} is verified`, { description: "The farmer has been told and can put listings on sale now." });
    onOpenChange(false);
    navigate({ to: "/admin/verifications" });
  }

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (!next) approve.reset();
        onOpenChange(next);
      }}
    >
      <DialogContent className="store-dialog">
        <DialogHeader>
          <DialogTitle>Approve {farmName}?</DialogTitle>
          <DialogDescription>
            {verification.submitted_by?.name ?? "The farmer"} is told straight away and can put listings on sale. Buyers see the farm as verified.
          </DialogDescription>
        </DialogHeader>
        {approve.error && <p className="field-error">{errorMessage(approve.error)}</p>}
        <div className="dialog-actions">
          <Button variant="ghost" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button onClick={confirm} disabled={approve.isPending}>
            {approve.isPending ? <LoaderCircle className="animate-spin" /> : <BadgeCheck />}
            Approve farm
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  );
}

function RejectDialog({ verification, open, onOpenChange }: DecisionProps) {
  const reject = useRejectVerification();
  const navigate = useNavigate();
  const [reason, setReason] = useState("");
  const [problem, setProblem] = useState<string | null>(null);
  const fieldError = problem ?? (reject.error instanceof ApiError ? reject.error.field("reason") : undefined);

  async function submit(event: FormEvent) {
    event.preventDefault();
    const text = reason.trim();
    if (text.length < 5) {
      setProblem("Write what the farmer needs to fix, or pick one of the reasons above.");
      return;
    }
    setProblem(null);
    if (!(await reject.mutateAsync({ id: verification.id, reason: text }).catch(() => null))) return;
    toast.success(`${verification.farm?.name ?? "The farm"} was rejected`, { description: "The farmer has been told why and can send new documents." });
    onOpenChange(false);
    navigate({ to: "/admin/verifications" });
  }

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (!next) {
          reject.reset();
          setProblem(null);
        }
        onOpenChange(next);
      }}
    >
      <DialogContent className="store-dialog">
        <DialogHeader>
          <DialogTitle>Reject this verification?</DialogTitle>
          <DialogDescription>Tell the farmer what to fix. They get your message and can send their documents again.</DialogDescription>
        </DialogHeader>
        <form className="form-stack" onSubmit={submit} noValidate>
          <div className="reason-chips" role="group" aria-label="Common reasons">
            {QUICK_REASONS.map((text) => (
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
          <div className="form-field">
            <Label htmlFor="reject-reason" className="field-label">
              Message to the farmer
            </Label>
            <textarea
              id="reject-reason"
              className="admin-textarea"
              maxLength={300}
              value={reason}
              aria-invalid={!!fieldError}
              onChange={(event) => {
                setReason(event.target.value);
                setProblem(null);
              }}
            />
            {fieldError ? <small className="field-error">{fieldError}</small> : <small className="field-hint">{reason.length}/300 characters</small>}
          </div>
          {reject.error && !fieldError && <p className="field-error">{errorMessage(reject.error)}</p>}
          <div className="dialog-actions">
            <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" variant="destructive" disabled={reject.isPending}>
              {reject.isPending && <LoaderCircle className="animate-spin" />}
              Reject and tell the farmer
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  );
}
