// Generiert die Facetten-Texturen der Shiny-Sticker-Karte ("Die Klebrigsten"):
// ein unregelmäßiges Voronoi-Muster (Lloyd-relaxiert → Zellen ungefähr gleich groß), jede Zelle
// eine "Facette" mit zufälliger Normalen. Ausgabe (SVG, viewBox 500×700 = Kartenformat 5:7):
//   facets-base.svg — zufälliger Grauwert je Zelle + feine Kanten (Textur für den Regenbogen-Shine)
//   facets-x.svg    — Grau = X-Anteil der Normalen (50 % = neutral)  → Licht bei Links/Rechts-Neigung
//   facets-y.svg    — Grau = Y-Anteil der Normalen (50 % = neutral)  → Licht bei Hoch/Runter-Neigung
// Aufruf: node scripts/generate-sticker-facets.js   (deterministisch per SEED)
const fs = require('fs');
const path = require('path');

const W = 500, H = 700;
const CELLS = 500;
const LLOYD_ITERATIONS = 10;
const SEED = 20260925;
const OUT = path.join(__dirname, '..', 'public', 'img', 'stickers');

// Mulberry32 — kleiner Seed-PRNG
function rng(seed) {
  let a = seed >>> 0;
  return () => {
    a = (a + 0x6d2b79f5) >>> 0;
    let t = a;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}
const random = rng(SEED);

// Polygon (Array von [x,y]) an der Halbebene "näher an a als an b" abschneiden (Sutherland–Hodgman)
function clip(poly, a, b) {
  const mx = (a[0] + b[0]) / 2, my = (a[1] + b[1]) / 2;
  const nx = b[0] - a[0], ny = b[1] - a[1];
  const inside = p => (p[0] - mx) * nx + (p[1] - my) * ny <= 0;
  const out = [];
  for (let i = 0; i < poly.length; i++) {
    const cur = poly[i], prev = poly[(i + poly.length - 1) % poly.length];
    const ci = inside(cur), pi = inside(prev);
    if (ci !== pi) {
      const dx = cur[0] - prev[0], dy = cur[1] - prev[1];
      const t = ((mx - prev[0]) * nx + (my - prev[1]) * ny) / (dx * nx + dy * ny);
      out.push([prev[0] + t * dx, prev[1] + t * dy]);
    }
    if (ci) out.push(cur);
  }
  return out;
}

function voronoi(sites) {
  return sites.map((s, i) => {
    let poly = [[0, 0], [W, 0], [W, H], [0, H]];
    for (let j = 0; j < sites.length && poly.length; j++) {
      if (j !== i) poly = clip(poly, s, sites[j]);
    }
    return poly;
  });
}

function centroid(poly) {
  let a = 0, cx = 0, cy = 0;
  for (let i = 0; i < poly.length; i++) {
    const [x0, y0] = poly[i], [x1, y1] = poly[(i + 1) % poly.length];
    const f = x0 * y1 - x1 * y0;
    a += f; cx += (x0 + x1) * f; cy += (y0 + y1) * f;
  }
  a *= 0.5;
  return Math.abs(a) < 1e-9 ? poly[0] : [cx / (6 * a), cy / (6 * a)];
}

// Zufällige Startpunkte → Lloyd-Relaxation (Punkte in die Zellschwerpunkte verschieben)
let sites = Array.from({ length: CELLS }, () => [random() * W, random() * H]);
let cells = voronoi(sites);
for (let it = 0; it < LLOYD_ITERATIONS; it++) {
  sites = cells.map(centroid);
  cells = voronoi(sites);
}

// Je Zelle: zufällige Facetten-Normale (Richtung gleichverteilt, Neigung 0.35–1) + Grundhelligkeit
const facets = cells.map(poly => {
  const angle = random() * Math.PI * 2;
  const tilt = 0.35 + random() * 0.65;
  return { poly, nx: Math.cos(angle) * tilt, ny: Math.sin(angle) * tilt, base: 0.15 + random() * 0.8 };
});

const hex = v => {
  const c = Math.round(Math.max(0, Math.min(1, v)) * 255).toString(16).padStart(2, '0');
  return `#${c}${c}${c}`;
};
// ganze Pixel reichen bei 500×700 (Karte wird ohnehin skaliert) und halten die SVGs klein
const points = poly => poly.map(([x, y]) => `${Math.round(x)},${Math.round(y)}`).join(' ');

function svg(fill, edges) {
  const body = facets.map(f => `<polygon points="${points(f.poly)}" fill="${fill(f)}"/>`).join('');
  const stroke = edges
    ? `<g fill="none" stroke="#000" stroke-opacity="0.55" stroke-width="0.8" stroke-linejoin="round">${facets.map(f => `<polygon points="${points(f.poly)}"/>`).join('')}</g>`
    : '';
  // shape-rendering crispEdges würde Zacken erzeugen — Standard-Antialiasing, aber gleiche Farbe als
  // Kontur (stroke) je Zelle schließt die feinen Haarlinien zwischen benachbarten Polygonen
  const seamless = facets.map(f => `<polygon points="${points(f.poly)}" fill="none" stroke="${fill(f)}" stroke-width="0.8"/>`).join('');
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${W} ${H}" width="${W}" height="${H}" preserveAspectRatio="none">${seamless}${body}${stroke}</svg>\n`;
}

fs.mkdirSync(OUT, { recursive: true });
fs.writeFileSync(path.join(OUT, 'facets-base.svg'), svg(f => hex(f.base), true));
fs.writeFileSync(path.join(OUT, 'facets-x.svg'), svg(f => hex(0.5 + 0.5 * f.nx), false));
fs.writeFileSync(path.join(OUT, 'facets-y.svg'), svg(f => hex(0.5 + 0.5 * f.ny), false));

const areas = cells.map(p => {
  let a = 0;
  for (let i = 0; i < p.length; i++) { const [x0, y0] = p[i], [x1, y1] = p[(i + 1) % p.length]; a += x0 * y1 - x1 * y0; }
  return Math.abs(a / 2);
});
const mean = areas.reduce((s, a) => s + a, 0) / areas.length;
console.log(`${CELLS} Zellen, Fläche Ø ${mean.toFixed(0)} px², min ${Math.min(...areas).toFixed(0)}, max ${Math.max(...areas).toFixed(0)} → ${OUT}`);
