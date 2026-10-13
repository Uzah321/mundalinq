import { useEffect, useState, type FormEvent, type ReactNode } from "react";
import { Link } from "@tanstack/react-router";
import { BadgeCheck, Bell, ClipboardList, LoaderCircle, LogOut, MessageCircle, Phone, Receipt, ShieldCheck } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { InputOTP, InputOTPGroup, InputOTPSlot } from "@/components/ui/input-otp";
import { Label } from "@/components/ui/label";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { isStaff } from "@/lib/admin";
import { ApiError, errorMessage } from "@/lib/api";
import {
  useConfirmTwoFactor,
  useRegister,
  useResendCode,
  useSession,
  useSignIn,
  useSignOut,
  useVerifyPhone,
  type RegisterInput,
  type TwoFactorChallenge,
} from "@/lib/auth";
import { formatPhone, initials } from "@/lib/format";
import type { User } from "@/lib/types";
import { cn } from "@/lib/utils";

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Why we're asking, e.g. "Sign in to add Roma tomatoes to your basket." */
  reason: string | null;
  onSignedIn: () => void;
};

export function AccountDialog({ open, onOpenChange, reason, onSignedIn }: Props) {
  const { user, signedIn } = useSession();
  // "verify" right after sign-up (the code is already on its way) or when asked for later.
  const [verify, setVerify] = useState<null | "just-sent" | "on-request">(null);

  useEffect(() => {
    if (!open) setVerify(null);
  }, [open]);

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="store-dialog account-dialog">
        {signedIn && user && verify ? (
          <VerifyPhone user={user} codeJustSent={verify === "just-sent"} onDone={() => setVerify(null)} />
        ) : signedIn && user ? (
          <AccountPanel user={user} onVerify={() => setVerify("on-request")} onSignedOut={() => onOpenChange(false)} />
        ) : signedIn ? (
          <div className="account-panel" aria-busy>
            <DialogTitle className="sr-only">Loading your account</DialogTitle>
            <LoaderCircle className="animate-spin" />
          </div>
        ) : (
          <AuthForms
            reason={reason}
            onSignedIn={() => {
              onSignedIn();
              onOpenChange(false);
            }}
            onRegistered={() => {
              onSignedIn();
              setVerify("just-sent");
            }}
          />
        )}
      </DialogContent>
    </Dialog>
  );
}

function AuthForms({ reason, onSignedIn, onRegistered }: { reason: string | null; onSignedIn: () => void; onRegistered: () => void }) {
  const [tab, setTab] = useState("sign-in");

  return (
    <>
      <DialogHeader>
        <DialogTitle>{tab === "sign-in" ? "Welcome back" : "Join MundaLinq"}</DialogTitle>
        <DialogDescription>{reason ?? "Buy straight from verified farms near you."}</DialogDescription>
      </DialogHeader>
      <Tabs value={tab} onValueChange={setTab}>
        <TabsList className="auth-tabs">
          <TabsTrigger value="sign-in">Sign in</TabsTrigger>
          <TabsTrigger value="register">Create account</TabsTrigger>
        </TabsList>
        <TabsContent value="sign-in">
          <SignInForm onSignedIn={onSignedIn} />
        </TabsContent>
        <TabsContent value="register">
          <RegisterForm onRegistered={onRegistered} />
        </TabsContent>
      </Tabs>
    </>
  );
}

export function SignInForm({ onSignedIn }: { onSignedIn: () => void }) {
  const signIn = useSignIn();
  const [login, setLogin] = useState("");
  const [password, setPassword] = useState("");
  const [challenge, setChallenge] = useState<TwoFactorChallenge | null>(null);
  const error = signIn.error instanceof ApiError ? signIn.error : null;

  // Signing in swaps this form for the account panel, so wait on the promise: callbacks passed to
  // mutate() are dropped once the form unmounts.
  async function submit(event: FormEvent) {
    event.preventDefault();
    const response = await signIn.mutateAsync({ login, password }).catch(() => null);
    if (!response) return;
    if ("token" in response) onSignedIn();
    else setChallenge(response);
  }

  if (challenge) {
    return (
      <StaffCodeStep
        challenge={challenge}
        onSignedIn={onSignedIn}
        onResend={async () => {
          const response = await signIn.mutateAsync({ login, password });
          if (!("token" in response)) setChallenge(response);
        }}
        onBack={() => {
          signIn.reset();
          setChallenge(null);
        }}
      />
    );
  }

  return (
    <form className="form-stack" onSubmit={submit} noValidate>
      <Field id="login" label="Phone number or email" error={error?.field("login")}>
        <Input id="login" autoComplete="username" placeholder="0771 234 567" value={login} onChange={(event) => setLogin(event.target.value)} required />
      </Field>
      <Field id="password" label="Password" error={error?.field("password")}>
        <Input id="password" type="password" autoComplete="current-password" value={password} onChange={(event) => setPassword(event.target.value)} required />
      </Field>
      {signIn.error && !hasFieldErrors(signIn.error) && <p className="field-error">{errorMessage(signIn.error)}</p>}
      <Button type="submit" className="form-submit" disabled={signIn.isPending}>
        {signIn.isPending && <LoaderCircle className="animate-spin" />}
        Sign in
      </Button>
    </form>
  );
}

type StaffCodeProps = {
  challenge: TwoFactorChallenge;
  onSignedIn: () => void;
  /** Signs in again, which texts a fresh code. */
  onResend: () => Promise<void>;
  onBack: () => void;
};

/** Second step of a staff sign-in: the code texted to their phone. */
function StaffCodeStep({ challenge, onSignedIn, onResend, onBack }: StaffCodeProps) {
  const confirm = useConfirmTwoFactor();
  const [code, setCode] = useState("");
  const [cooldown, setCooldown] = useState(60);
  const [resending, setResending] = useState(false);
  const expired = confirm.error instanceof ApiError && !!confirm.error.field("challenge");

  useEffect(() => {
    if (cooldown <= 0) return;
    const timer = window.setTimeout(() => setCooldown(cooldown - 1), 1000);
    return () => window.clearTimeout(timer);
  }, [cooldown]);

  async function submit(value: string) {
    if (await confirm.mutateAsync({ challenge: challenge.challenge, code: value }).catch(() => null)) onSignedIn();
    else setCode("");
  }

  async function resend() {
    setResending(true);
    try {
      await onResend();
      confirm.reset();
      setCode("");
      setCooldown(60);
    } catch (error) {
      toast.error(errorMessage(error));
    } finally {
      setResending(false);
    }
  }

  return (
    <div className="otp-step staff-code">
      <p className="staff-code-text">{challenge.message} Staff accounts need this extra step.</p>
      <InputOTP
        maxLength={6}
        value={code}
        onChange={setCode}
        onComplete={submit}
        disabled={confirm.isPending || expired}
        autoFocus
        inputMode="numeric"
        aria-label="Sign-in code"
      >
        <InputOTPGroup>
          {Array.from({ length: 6 }, (_, index) => (
            <InputOTPSlot key={index} index={index} className="otp-slot" />
          ))}
        </InputOTPGroup>
      </InputOTP>
      {confirm.isPending && <LoaderCircle className="animate-spin" />}
      {confirm.error && <p className="field-error">{errorMessage(confirm.error)}</p>}
      <div className="otp-actions">
        <Button variant="link" onClick={resend} disabled={(cooldown > 0 && !expired) || resending}>
          {cooldown > 0 && !expired ? `Send a new code in ${cooldown}s` : "Send a new code"}
        </Button>
        <Button variant="ghost" onClick={onBack}>
          Back
        </Button>
      </div>
    </div>
  );
}

function RegisterForm({ onRegistered }: { onRegistered: () => void }) {
  const register = useRegister();
  const [form, setForm] = useState<RegisterInput>({
    name: "",
    phone: "",
    email: "",
    password: "",
    password_confirmation: "",
    role: "buyer",
  });
  const error = register.error instanceof ApiError ? register.error : null;
  const set = (field: keyof RegisterInput) => (event: { target: { value: string } }) => setForm({ ...form, [field]: event.target.value });

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (await register.mutateAsync(form).catch(() => null)) onRegistered();
  }

  return (
    <form className="form-stack" onSubmit={submit} noValidate>
      <div className="choice-row" role="radiogroup" aria-label="I'm buying for">
        {(
          [
            ["buyer", "My household"],
            ["business_buyer", "My business"],
          ] as const
        ).map(([role, label]) => (
          <button
            key={role}
            type="button"
            role="radio"
            aria-checked={form.role === role}
            className={cn("choice", form.role === role && "selected")}
            onClick={() => setForm({ ...form, role })}
          >
            {label}
          </button>
        ))}
      </div>
      <Field id="name" label="Full name" error={error?.field("name")}>
        <Input id="name" autoComplete="name" value={form.name} onChange={set("name")} required />
      </Field>
      <Field id="phone" label="Mobile number" hint="We'll text you a code to confirm it." error={error?.field("phone")}>
        <Input id="phone" type="tel" autoComplete="tel" placeholder="0771 234 567" value={form.phone} onChange={set("phone")} required />
      </Field>
      <Field id="email" label="Email (optional)" error={error?.field("email")}>
        <Input id="email" type="email" autoComplete="email" value={form.email} onChange={set("email")} />
      </Field>
      <Field id="new-password" label="Password" hint="At least 8 characters." error={error?.field("password")}>
        <Input id="new-password" type="password" autoComplete="new-password" value={form.password} onChange={set("password")} required />
      </Field>
      <Field id="password-confirmation" label="Confirm password">
        <Input
          id="password-confirmation"
          type="password"
          autoComplete="new-password"
          value={form.password_confirmation}
          onChange={set("password_confirmation")}
          required
        />
      </Field>
      {register.error && !hasFieldErrors(register.error) && <p className="field-error">{errorMessage(register.error)}</p>}
      <Button type="submit" className="form-submit" disabled={register.isPending}>
        {register.isPending && <LoaderCircle className="animate-spin" />}
        Create account
      </Button>
    </form>
  );
}

/** Confirming the phone number on its own, e.g. from checkout. */
export function VerifyPhoneDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
  const { user } = useSession();

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="store-dialog account-dialog">
        {user ? (
          <VerifyPhone user={user} codeJustSent={false} onDone={() => onOpenChange(false)} />
        ) : (
          <DialogTitle className="sr-only">Confirm your number</DialogTitle>
        )}
      </DialogContent>
    </Dialog>
  );
}

function VerifyPhone({ user, codeJustSent, onDone }: { user: User; codeJustSent: boolean; onDone: () => void }) {
  const verify = useVerifyPhone();
  const resend = useResendCode();
  const [code, setCode] = useState("");
  const [cooldown, setCooldown] = useState(codeJustSent ? 60 : 0);
  const [sent, setSent] = useState(codeJustSent);

  useEffect(() => {
    if (cooldown <= 0) return;
    const timer = window.setTimeout(() => setCooldown(cooldown - 1), 1000);
    return () => window.clearTimeout(timer);
  }, [cooldown]);

  function submit(value: string) {
    verify.mutate(value, {
      onSuccess: () => {
        toast.success("Your phone number is verified");
        onDone();
      },
      onError: () => setCode(""),
    });
  }

  function send() {
    resend.mutate(undefined, {
      onSuccess: () => {
        setSent(true);
        setCooldown(60);
      },
      onError: (error) => toast.error(errorMessage(error)),
    });
  }

  return (
    <>
      <DialogHeader>
        <DialogTitle>Confirm your number</DialogTitle>
        <DialogDescription>
          {sent
            ? `Enter the 6-digit code we sent to ${formatPhone(user.phone)}.`
            : `We'll text a 6-digit code to ${formatPhone(user.phone)}. You need a confirmed number to place orders.`}
        </DialogDescription>
      </DialogHeader>

      {sent ? (
        <div className="otp-step">
          <InputOTP
            maxLength={6}
            value={code}
            onChange={setCode}
            onComplete={submit}
            disabled={verify.isPending}
            autoFocus
            inputMode="numeric"
            aria-label="Verification code"
          >
            <InputOTPGroup>
              {Array.from({ length: 6 }, (_, index) => (
                <InputOTPSlot key={index} index={index} className="otp-slot" />
              ))}
            </InputOTPGroup>
          </InputOTP>
          {verify.error && <p className="field-error">{errorMessage(verify.error)}</p>}
          <div className="otp-actions">
            <Button variant="link" onClick={send} disabled={cooldown > 0 || resend.isPending}>
              {cooldown > 0 ? `Resend code in ${cooldown}s` : "Resend code"}
            </Button>
            <Button variant="ghost" onClick={onDone}>
              Later
            </Button>
          </div>
        </div>
      ) : (
        <div className="otp-actions">
          <Button onClick={send} disabled={resend.isPending}>
            {resend.isPending && <LoaderCircle className="animate-spin" />}
            Send code
          </Button>
          <Button variant="ghost" onClick={onDone}>
            Later
          </Button>
        </div>
      )}
    </>
  );
}

function AccountPanel({ user, onVerify, onSignedOut }: { user: User; onVerify: () => void; onSignedOut: () => void }) {
  const signOut = useSignOut();

  return (
    <div className="account-panel">
      <span className="account-avatar" aria-hidden>
        {initials(user.name)}
      </span>
      <div>
        <DialogTitle asChild>
          <h3>{user.name}</h3>
        </DialogTitle>
        <DialogDescription>
          {formatPhone(user.phone)}
          {user.email && (
            <>
              <br />
              {user.email}
            </>
          )}
        </DialogDescription>
      </div>

      {user.phone_verified ? (
        <span className="account-status">
          <BadgeCheck /> Phone verified
        </span>
      ) : (
        <div className="account-verify">
          <p>Confirm your number to place orders.</p>
          <Button variant="secondary" onClick={onVerify}>
            <Phone /> Verify phone
          </Button>
        </div>
      )}

      <nav className="account-links" aria-label="Your account">
        <Link to="/orders">
          <Receipt /> My orders
        </Link>
        <Link to="/quotes">
          <ClipboardList /> Requests for quotes
        </Link>
        <Link to="/messages">
          <MessageCircle /> Messages
        </Link>
        <Link to="/notifications">
          <Bell /> Notifications
        </Link>
        {isStaff(user) && (
          <Link to="/admin">
            <ShieldCheck /> Admin
          </Link>
        )}
      </nav>

      <Button
        variant="outline"
        disabled={signOut.isPending}
        onClick={() => signOut.mutateAsync().then(onSignedOut)}
      >
        <LogOut /> Sign out
      </Button>
    </div>
  );
}

function Field({ id, label, hint, error, children }: { id: string; label: string; hint?: string; error?: string; children: ReactNode }) {
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

/** Field errors show under their inputs; anything else (rate limits, suspensions, outages) shows once below the form. */
function hasFieldErrors(error: unknown): boolean {
  return error instanceof ApiError && Object.keys(error.fieldErrors).length > 0;
}
