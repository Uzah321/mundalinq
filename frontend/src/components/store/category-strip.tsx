import { LayoutGrid } from "lucide-react";
import { Button } from "@/components/ui/button";
import { useCategories } from "@/lib/queries";
import { cn } from "@/lib/utils";
import { CategoryIcon, categoryPhoto } from "./category-visual";

type Props = {
  selected: string | undefined;
  onSelect: (slug: string | undefined) => void;
};

export function CategoryStrip({ selected, onSelect }: Props) {
  const { data: categories, isPending } = useCategories();

  return (
    <section className="category-section" aria-labelledby="category-heading">
      <div className="section-heading">
        <h2 id="category-heading">Shop by category</h2>
        <span className="section-note">Something good for every day</span>
      </div>

      <div className="categories">
        <Button
          variant="ghost"
          className={cn("category-item", !selected && "selected")}
          aria-pressed={!selected}
          onClick={() => onSelect(undefined)}
        >
          <span className="category-photo">
            <LayoutGrid />
          </span>
          All products
        </Button>

        {isPending &&
          Array.from({ length: 6 }, (_, index) => (
            <span key={index} className="category-item" aria-hidden>
              <span className="category-photo skeleton" />
              <span className="skeleton skeleton-line short" />
            </span>
          ))}

        {categories?.map((category) => {
          const photo = categoryPhoto(category.slug);
          return (
            <Button
              key={category.id}
              variant="ghost"
              className={cn("category-item", selected === category.slug && "selected")}
              aria-pressed={selected === category.slug}
              onClick={() => onSelect(category.slug)}
            >
              <span className="category-photo">
                {photo ? <img src={photo} alt="" /> : <CategoryIcon slug={category.slug} />}
              </span>
              {category.name}
            </Button>
          );
        })}
      </div>
    </section>
  );
}
