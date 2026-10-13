import { useMemo } from "react";
import { Apple, Bean, Beef, Carrot, Egg, Hexagon, Leaf, Sprout, Wheat, type LucideIcon } from "lucide-react";
import { useCategories } from "@/lib/queries";

const ICONS: Record<string, LucideIcon> = {
  vegetables: Carrot,
  fruit: Apple,
  "grains-and-cereals": Wheat,
  "legumes-and-nuts": Bean,
  livestock: Beef,
  "eggs-and-dairy": Egg,
  honey: Hexagon,
  "herbs-and-spices": Leaf,
};

// A photo named after a top-level category slug (src/assets/categories/vegetables.jpg) replaces its icon.
const photoFiles = import.meta.glob<string>("/src/assets/categories/*.{jpg,jpeg,png,webp}", {
  eager: true,
  import: "default",
});
const PHOTOS: Record<string, string> = Object.fromEntries(
  Object.entries(photoFiles).map(([path, url]) => [path.split("/").pop()!.replace(/\.\w+$/, ""), url]),
);

export function categoryPhoto(slug: string): string | undefined {
  return PHOTOS[slug];
}

export function CategoryIcon({ slug }: { slug: string }) {
  const Icon = ICONS[slug] ?? Sprout;
  return <Icon aria-hidden />;
}

/** Maps any category slug to its top-level one, so a "tomatoes" listing borrows the vegetables icon. */
export function useRootCategory() {
  const { data: categories } = useCategories();

  return useMemo(() => {
    const roots = new Map<string, string>();
    for (const category of categories ?? []) {
      roots.set(category.slug, category.slug);
      for (const child of category.children ?? []) roots.set(child.slug, category.slug);
    }
    return (slug: string) => roots.get(slug) ?? slug;
  }, [categories]);
}

/** Stand-in for a listing without photos. */
export function ProductPlaceholder({ slug, className = "product-placeholder" }: { slug: string; className?: string }) {
  const rootOf = useRootCategory();

  return (
    <span className={className}>
      <CategoryIcon slug={rootOf(slug)} />
    </span>
  );
}
