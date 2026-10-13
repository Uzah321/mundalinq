import { ArrowRight, Leaf } from "lucide-react";
import { Button } from "@/components/ui/button";

// Put a wide photo at src/assets/hero.(jpg|png|webp) to use it instead of the illustration.
// Like the original design, it should fade to the banner's mint on the left so the copy stays legible.
const heroFiles = import.meta.glob<string>("/src/assets/hero.{jpg,jpeg,png,webp}", { eager: true, import: "default" });
const heroImage = Object.values(heroFiles)[0];

export function HarvestBanner({ onShop }: { onShop: () => void }) {
  return (
    <section className="harvest-banner" aria-labelledby="harvest-heading">
      {heroImage ? <img src={heroImage} alt="" /> : <HarvestArt />}
      <div className="banner-copy">
        <span className="banner-eyebrow">
          <span />
          FRESH FROM THE FARM
        </span>
        <h2 id="harvest-heading">
          A little fresher.
          <br />A lot closer.
        </h2>
        <p>Seasonal goodness, straight from local farms.</p>
        <Button onClick={onShop}>
          Shop the harvest <ArrowRight />
        </Button>
      </div>
      <div className="harvest-stamp" aria-hidden>
        <Leaf />
        <span>GROWN WITH</span>
        <strong>goodness</strong>
      </div>
    </section>
  );
}

/** Sun over planted fields, in the brand colours. */
function HarvestArt() {
  return (
    <svg className="harvest-art" viewBox="0 0 640 320" preserveAspectRatio="xMidYMax slice" aria-hidden>
      <defs>
        <linearGradient id="harvest-fade" x1="0" x2="1" y1="0" y2="0">
          <stop offset="0" className="fade-from" />
          <stop offset="0.32" className="fade-to" />
        </linearGradient>
      </defs>
      <circle className="sun-glow" cx="468" cy="104" r="86" />
      <circle className="sun" cx="468" cy="104" r="50" />
      <path className="hill far" d="M0 214 C 120 160 250 168 372 196 S 560 176 640 150 V320 H0 Z" />
      <path className="hill mid" d="M0 252 C 150 204 300 214 430 240 S 590 222 640 214 V320 H0 Z" />
      <path className="hill near" d="M60 320 C 190 262 400 252 640 262 V320 Z" />
      <g className="rows">
        <path d="M150 320 C 260 286 450 276 640 280" />
        <path d="M250 320 C 350 298 500 292 640 296" />
        <path d="M370 320 C 450 308 550 305 640 308" />
      </g>
      <g className="sprouts">
        <Sprig x={318} y={210} />
        <Sprig x={360} y={216} scale={0.8} />
        <Sprig x={560} y={204} scale={0.9} />
      </g>
      <rect width="640" height="320" fill="url(#harvest-fade)" />
    </svg>
  );
}

function Sprig({ x, y, scale = 1 }: { x: number; y: number; scale?: number }) {
  return (
    <g transform={`translate(${x} ${y}) scale(${scale})`}>
      <path className="stem" d="M0 0 V-22" />
      <path className="leaf" d="M0 -12 C -12 -14 -16 -24 -14 -30 C -6 -28 0 -22 0 -12 Z" />
      <path className="leaf" d="M0 -18 C 10 -20 16 -30 15 -36 C 6 -35 0 -28 0 -18 Z" />
    </g>
  );
}
