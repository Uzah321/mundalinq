import { useState, type FormEvent } from "react";
import { createFileRoute, Link } from "@tanstack/react-router";
import { ArrowLeft, LoaderCircle, Send, TriangleAlert } from "lucide-react";
import { toast } from "sonner";
import { AdminField, ConfirmDialog, Fact, Facts, ItemState, Pill, Section, useSubmit } from "@/components/admin/ui";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { apiPath, disputeReasons, disputeResolutions, refundStatuses, statusOf, useAdminAction, useAdminItem, useFileUrl } from "@/lib/admin";
import { api, errorMessage } from "@/lib/api";
import { formatDateTime, formatMoney, timeAgo, toMinor } from "@/lib/format";
import type { Dispute } from "@/lib/types";
import { cn } from "@/lib/utils";

export const Route = createFileRoute("/admin/disputes/$disputeId")({
  component: DisputePage,
});

const PARTIES = { buyer: "Buyer", farm: "Farm", staff: "MundaLinq" } as const;

type Resolution = NonNullable<Dispute["resolution"]>;

function DisputePage() {
  const { disputeId } = Route.useParams();
  // Staff read disputes through the same endpoint as the buyer and the farm.
  const query = useAdminItem<Dispute>(`/disputes/${disputeId}`);
  const dispute = query.data;
  if (!dispute) return <ItemState query={query} missing="This dispute doesn't exist" />;

  const money = (amount: string) => formatMoney(amount, dispute.currency);
  const tone = dispute.status === "open" ? "wait" : dispute.status === "resolved" ? "good" : "plain";

  return (
    <>
      <Link to="/admin/disputes" className="admin-back">
        <ArrowLeft /> All disputes
      </Link>
      <div className="admin-heading">
        <Pill tone={tone}>{dispute.status === "open" ? "Waiting for your decision" : dispute.status === "resolved" ? "Resolved" : "Withdrawn by the buyer"}</Pill>
        <h1>{disputeReasons[dispute.reason] ?? dispute.reason}</h1>
        <p>
          {dispute.buyer.name} reported a problem with {dispute.seller_order.number} from {dispute.farm.name}
          {dispute.created_at && `, ${timeAgo(dispute.created_at)}`}.
        </p>
      </div>

      {dispute.status === "resolved" && dispute.resolution && (
        <div className="admin-outcome approved">
          <p>
            {disputeResolutions[dispute.resolution]}
            {dispute.refund_amount && ` of ${money(dispute.refund_amount)}`}
            {dispute.resolved_at && `, decided ${formatDateTime(dispute.resolved_at)}`}.
            {dispute.resolution_note && (
              <>
                <br />“{dispute.resolution_note}”
              </>
            )}
          </p>
        </div>
      )}

      <div className="admin-grid">
        <Section title="The problem">
          {dispute.description ? <p className="admin-description admin-quote">“{dispute.description}”</p> : <p className="admin-hint">No description given.</p>}
          <Facts>
            <Fact label="Farm order">
              <Link to="/admin/orders" search={{ q: dispute.seller_order.number }}>
                {dispute.seller_order.number}
              </Link>{" "}
              · {money(dispute.seller_order.total)}
            </Fact>
            <Fact label="Asks for back">{dispute.requested_refund ? money(dispute.requested_refund) : "Not said"}</Fact>
          </Facts>
        </Section>

        <Section title="Photos">
          {dispute.evidence?.length ? (
            <div className="admin-photo-strip">
              {dispute.evidence.map((photo) => (
                <EvidencePhoto key={photo.id} url={photo.url} party={PARTIES[photo.party]} />
              ))}
            </div>
          ) : (
            <p className="admin-hint">No photos were added.</p>
          )}
        </Section>

        <Section title="Messages" wide>
          <Thread dispute={dispute} />
        </Section>

        {!!dispute.refunds?.length && (
          <Section title="Refunds" wide>
            <ul className="admin-mini-list">
              {dispute.refunds.map((refund) => {
                const status = statusOf(refundStatuses, refund.status);
                return (
                  <li key={refund.id}>
                    <strong>{money(refund.amount)}</strong>
                    <small>{[refund.provider_reference, refund.failure_reason].filter(Boolean).join(" · ")}</small>
                    <Pill tone={status.tone}>{status.label}</Pill>
                  </li>
                );
              })}
            </ul>
          </Section>
        )}

        {dispute.status === "open" && (
          <Section title="Your decision" wide>
            <Decision dispute={dispute} />
          </Section>
        )}
      </div>
    </>
  );
}

function EvidencePhoto({ url, party }: { url: string; party: string }) {
  const file = useFileUrl(apiPath(url));
  return (
    <figure className="admin-evidence">
      {file.error ? (
        <span className="doc-message">
          <TriangleAlert /> {errorMessage(file.error)}
        </span>
      ) : file.url ? (
        <a href={file.url} target="_blank" rel="noreferrer">
          <img src={file.url} alt={`Photo from the ${party.toLowerCase()}`} />
        </a>
      ) : (
        <span className="skeleton" />
      )}
      <figcaption>From the {party.toLowerCase()}</figcaption>
    </figure>
  );
}

function Thread({ dispute }: { dispute: Dispute }) {
  const [body, setBody] = useState("");
  const send = useAdminAction((text: string) => api(`/disputes/${dispute.id}/messages`, { method: "POST", body: { body: text } }));
  const submit = useSubmit();

  async function post(event: FormEvent) {
    event.preventDefault();
    if (!body.trim()) return;
    if (await submit.run(() => send.mutateAsync(body.trim()))) setBody("");
  }

  return (
    <>
      {dispute.messages?.length ? (
        <ol className="admin-thread">
          {dispute.messages.map((message) => (
            <li key={message.id} className={cn("admin-message", message.party)}>
              <small>
                {PARTIES[message.party]} · {message.author.name}
                {message.created_at && ` · ${formatDateTime(message.created_at)}`}
              </small>
              <p>{message.body}</p>
            </li>
          ))}
        </ol>
      ) : (
        <p className="admin-hint">No messages yet.</p>
      )}
      {dispute.status === "open" && (
        <form className="admin-reply" onSubmit={post}>
          <textarea
            className="admin-textarea"
            value={body}
            maxLength={2000}
            onChange={(event) => setBody(event.target.value)}
            placeholder="Write to the buyer and the farm, e.g. to ask for a photo"
            aria-label="Message to the buyer and the farm"
          />
          {submit.error != null && <p className="field-error">{errorMessage(submit.error)}</p>}
          <Button type="submit" disabled={submit.pending}>
            {submit.pending ? <LoaderCircle className="animate-spin" /> : <Send />} Send to both
          </Button>
        </form>
      )}
    </>
  );
}

function Decision({ dispute }: { dispute: Dispute }) {
  const [resolution, setResolution] = useState<Resolution>("refund_full");
  const [amount, setAmount] = useState(dispute.requested_refund ?? "");
  const [note, setNote] = useState("");
  const [problems, setProblems] = useState<{ amount?: string; note?: string }>({});
  const [confirming, setConfirming] = useState(false);
  const resolve = useAdminAction((input: { resolution: Resolution; refund_amount?: string; note: string }) =>
    api(`/admin/disputes/${dispute.id}/resolve`, { method: "POST", body: input }),
  );
  const money = (value: string) => formatMoney(value, dispute.currency);
  const refund = resolution === "refund_full" ? dispute.seller_order.total : resolution === "refund_partial" ? amount : null;

  function check(event: FormEvent) {
    event.preventDefault();
    const found: typeof problems = {};
    if (resolution === "refund_partial") {
      const minor = toMinor(amount);
      const total = toMinor(dispute.seller_order.total) ?? 0;
      if (minor === null || minor <= 0 || minor >= total) found.amount = `Enter an amount above $0 and below ${money(dispute.seller_order.total)}.`;
    }
    if (note.trim().length < 5) found.note = "Explain your decision in a sentence. Both sides receive it.";
    setProblems(found);
    if (!Object.keys(found).length) setConfirming(true);
  }

  return (
    <form className="form-stack admin-decision-form" onSubmit={check} noValidate>
      <div className="choice-row three" role="radiogroup" aria-label="Decision">
        {(Object.keys(disputeResolutions) as Resolution[]).map((value) => (
          <button
            key={value}
            type="button"
            role="radio"
            aria-checked={resolution === value}
            className={cn("choice", resolution === value && "selected")}
            onClick={() => setResolution(value)}
          >
            {disputeResolutions[value]}
            <small>
              {value === "refund_full" ? money(dispute.seller_order.total) : value === "refund_partial" ? "You choose the amount" : "The farm is paid in full"}
            </small>
          </button>
        ))}
      </div>
      {resolution === "refund_partial" && (
        <AdminField id="refund-amount" label="Amount to refund" error={problems.amount} hint={`Up to ${money(dispute.seller_order.total)}`}>
          <Input id="refund-amount" inputMode="decimal" value={amount} onChange={(event) => setAmount(event.target.value.replace(/[^0-9.]/g, ""))} placeholder="5.00" />
        </AdminField>
      )}
      <AdminField id="resolution-note" label="Your decision, in a sentence" error={problems.note} hint="Sent to the buyer and the farm.">
        <textarea id="resolution-note" className="admin-textarea" maxLength={1000} value={note} onChange={(event) => setNote(event.target.value)} />
      </AdminField>
      <div className="dialog-actions">
        <Button type="submit">Resolve dispute</Button>
      </div>

      <ConfirmDialog
        open={confirming}
        onOpenChange={setConfirming}
        title={refund ? `Refund ${money(refund)} to ${dispute.buyer.name}?` : "Resolve without a refund?"}
        description={
          refund
            ? `The refund is taken from ${dispute.farm.name}'s earnings for this order and sent to the buyer's mobile money. The rest is released to the farm.`
            : `${dispute.farm.name} is paid in full and the buyer gets nothing back.`
        }
        confirmLabel="Resolve"
        onConfirm={async () => {
          await resolve.mutateAsync({ resolution, note: note.trim(), ...(resolution === "refund_partial" ? { refund_amount: amount } : {}) });
          toast.success("Dispute resolved", { description: "The buyer and the farm have been told." });
        }}
      />
    </form>
  );
}
