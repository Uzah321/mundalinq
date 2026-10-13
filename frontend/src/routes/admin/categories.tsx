import { useState, type FormEvent } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { createFileRoute } from "@tanstack/react-router";
import { LoaderCircle, Pencil, Plus } from "lucide-react";
import { toast } from "sonner";
import { AdminField, ItemState, PageHeading, Pill, useSubmit } from "@/components/admin/ui";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { useAdminAction, useAdminItem } from "@/lib/admin";
import { api } from "@/lib/api";
import type { Category } from "@/lib/types";

export const Route = createFileRoute("/admin/categories")({
  component: Categories,
});

/** What the dialog is for: a new category (optionally under a parent) or an existing one. */
type Editing = { category?: Category; parentId?: string | null };

function Categories() {
  const query = useAdminItem<Category[]>("/admin/categories");
  const [editing, setEditing] = useState<Editing | null>(null);
  const categories = query.data;
  if (!categories) return <ItemState query={query} missing="Categories aren't available" />;

  return (
    <>
      <PageHeading title="Categories" text="What farmers can list under and buyers can browse. Categories are never deleted, because listings use them; hide one instead.">
        <Button onClick={() => setEditing({ parentId: null })}>
          <Plus /> Add category
        </Button>
      </PageHeading>

      <ul className="admin-category-list">
        {categories.map((parent) => (
          <li key={parent.id} className="admin-card">
            <CategoryRow category={parent} onEdit={() => setEditing({ category: parent })} />
            <ul>
              {parent.children?.map((child) => (
                <li key={child.id}>
                  <CategoryRow category={child} onEdit={() => setEditing({ category: child })} />
                </li>
              ))}
            </ul>
            <Button variant="ghost" size="sm" className="admin-add-child" onClick={() => setEditing({ parentId: parent.id })}>
              <Plus /> Add a kind of {parent.name.toLowerCase()}
            </Button>
          </li>
        ))}
      </ul>

      {editing && <CategoryDialog editing={editing} parents={categories} onClose={() => setEditing(null)} />}
    </>
  );
}

function CategoryRow({ category, onEdit }: { category: Category; onEdit: () => void }) {
  return (
    <div className="admin-category">
      <span>
        <strong>{category.name}</strong>
        {category.description && <small>{category.description}</small>}
      </span>
      <span className="admin-pills">
        {category.requires_approval && <Pill tone="wait">Listings checked first</Pill>}
        {!category.is_active && <Pill tone="bad">Hidden</Pill>}
      </span>
      <Button variant="ghost" size="icon" onClick={onEdit} aria-label={`Edit ${category.name}`}>
        <Pencil />
      </Button>
    </div>
  );
}

function CategoryDialog({ editing, parents, onClose }: { editing: Editing; parents: Category[]; onClose: () => void }) {
  const existing = editing.category;
  const [name, setName] = useState(existing?.name ?? "");
  const [description, setDescription] = useState(existing?.description ?? "");
  const [parentId, setParentId] = useState(existing ? existing.parent_id : (editing.parentId ?? null));
  const [checked, setChecked] = useState(existing?.requires_approval ?? false);
  const [active, setActive] = useState(existing?.is_active ?? true);
  const [problem, setProblem] = useState<string | null>(null);
  const queryClient = useQueryClient();
  const submit = useSubmit();
  const save = useAdminAction((body: Record<string, unknown>) =>
    existing ? api(`/admin/categories/${existing.id}`, { method: "PATCH", body }) : api("/admin/categories", { method: "POST", body }),
  );
  // A category with its own subcategories has to stay at the top.
  const canMove = !existing?.children?.length;

  async function send(event: FormEvent) {
    event.preventDefault();
    if (!name.trim()) {
      setProblem("Give the category a name.");
      return;
    }
    setProblem(null);
    const body = {
      name: name.trim(),
      description: description.trim() || null,
      requires_approval: checked,
      is_active: active,
      ...(canMove ? { parent_id: parentId } : {}),
    };
    if (await submit.run(() => save.mutateAsync(body))) {
      // The storefront and apps read the public list; drop this browser's copy of it too.
      await queryClient.invalidateQueries({ queryKey: ["categories"] });
      toast.success(existing ? `${name.trim()} saved` : `${name.trim()} added`);
      onClose();
    }
  }

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="store-dialog">
        <DialogHeader>
          <DialogTitle>{existing ? `Edit ${existing.name}` : "Add a category"}</DialogTitle>
          <DialogDescription>Farmers choose from these when they list, and buyers browse by them.</DialogDescription>
        </DialogHeader>
        <form className="form-stack" onSubmit={send} noValidate>
          <AdminField id="category-name" label="Name" error={problem ?? submit.fieldError("name")}>
            <Input id="category-name" value={name} maxLength={80} onChange={(event) => setName(event.target.value)} placeholder="e.g. Leafy greens" />
          </AdminField>
          {canMove && (
            <AdminField id="category-parent" label="Sits under" error={submit.fieldError("parent_id")}>
              <select id="category-parent" className="admin-select" value={parentId ?? ""} onChange={(event) => setParentId(event.target.value || null)}>
                <option value="">Nothing: a main category</option>
                {parents
                  .filter((parent) => parent.id !== existing?.id)
                  .map((parent) => (
                    <option key={parent.id} value={parent.id}>
                      {parent.name}
                    </option>
                  ))}
              </select>
            </AdminField>
          )}
          <AdminField id="category-description" label="Description (optional)" error={submit.fieldError("description")}>
            <Input id="category-description" value={description} maxLength={255} onChange={(event) => setDescription(event.target.value)} />
          </AdminField>
          <label className="admin-check">
            <input type="checkbox" checked={checked} onChange={(event) => setChecked(event.target.checked)} />
            <span>
              <strong>Check listings before they go live</strong>
              <small>New listings here wait under Listings › To review until staff approve them.</small>
            </span>
          </label>
          <label className="admin-check">
            <input type="checkbox" checked={active} onChange={(event) => setActive(event.target.checked)} />
            <span>
              <strong>Show to farmers and buyers</strong>
              <small>Untick to hide it. Listings already in it stay as they are.</small>
            </span>
          </label>
          {submit.generalError && <p className="field-error">{submit.generalError}</p>}
          <div className="dialog-actions">
            <Button type="button" variant="ghost" onClick={onClose}>
              Cancel
            </Button>
            <Button type="submit" disabled={submit.pending}>
              {submit.pending && <LoaderCircle className="animate-spin" />}
              {existing ? "Save" : "Add category"}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  );
}
