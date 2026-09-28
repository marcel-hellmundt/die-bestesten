// Erzeugt public/img/geo/countries.json (GeoJSON; .json statt .geojson, damit der Webserver es als JSON ausliefert und komprimiert) für die Karte (/karte): Ländergrenzen, um nicht besuchte Länder
// dunkler zu tönen. Quelle: Natural Earth 1:50m Admin-0 Countries (Public Domain), z.B.
//   https://raw.githubusercontent.com/nvkelso/natural-earth-vector/master/geojson/ne_50m_admin_0_countries.geojson
// Aufruf: node webapp/scripts/build-country-geojson.js <quelle.geojson>
// Reduziert auf das Nötige: je Feature nur properties.iso (ISO-3166-Alpha-2, klein, wie country.id / img/flags),
// Koordinaten auf 2 Nachkommastellen (~1 km) gerundet, doppelte Punkte danach entfernt.
const fs = require('fs');
const path = require('path');

const src = process.argv[2];
if (!src) { console.error('Quelle fehlt: node build-country-geojson.js <ne_50m_admin_0_countries.geojson>'); process.exit(1); }

const round = n => Math.round(n * 100) / 100;
const ring = r => {
  const out = [];
  for (const [x, y] of r) {
    const p = [round(x), round(y)];
    const last = out[out.length - 1];
    if (!last || last[0] !== p[0] || last[1] !== p[1]) out.push(p);
  }
  return out.length >= 4 ? out : null; // zu kleine Ringe (winzige Inseln) fallen weg
};
const polygon = rings => { const r = rings.map(ring).filter(Boolean); return r.length ? r : null; };

// Gebiete ohne eigenen ISO-Code in Natural Earth → dem Land zuordnen, zu dem sie international gezählt werden
// (sonst fehlen sie in der Ebene und wirken auf der Karte wie "besucht")
const NO_ISO_FALLBACK = { SOL: 'SO' /* Somaliland */, CYN: 'CY' /* Nordzypern */, KAS: 'IN' /* Siachen-Gletscher */ };

const data = JSON.parse(fs.readFileSync(src, 'utf8'));
const features = [];
for (const f of data.features) {
  const p = f.properties;
  // ISO_A2 ist bei manchen Ländern (z.B. Frankreich, Norwegen) "-99" → ISO_A2_EH ist dort gesetzt
  const iso = [p.ISO_A2_EH, p.ISO_A2, p.WB_A2].find(c => c && c !== '-99' && /^[A-Z]{2}$/.test(c))
    ?? NO_ISO_FALLBACK[p.ADM0_A3]
    ?? 'XX'; // unbekannt → bleibt trotzdem drin und immer getönt (nie "besucht"), statt eine Lücke zu lassen
  if (iso === 'XX') console.warn(`ohne ISO-Code, immer getönt: ${p.ADMIN} (${p.ADM0_A3})`);
  if (!f.geometry) continue;
  let geometry;
  if (f.geometry.type === 'Polygon') {
    const coords = polygon(f.geometry.coordinates);
    if (!coords) continue;
    geometry = { type: 'Polygon', coordinates: coords };
  } else if (f.geometry.type === 'MultiPolygon') {
    const coords = f.geometry.coordinates.map(polygon).filter(Boolean);
    if (!coords.length) continue;
    geometry = { type: 'MultiPolygon', coordinates: coords };
  } else continue;
  features.push({ type: 'Feature', properties: { iso: iso.toLowerCase() }, geometry });
}

const out = path.join(__dirname, '..', 'public', 'img', 'geo', 'countries.json');
fs.mkdirSync(path.dirname(out), { recursive: true });
fs.writeFileSync(out, JSON.stringify({ type: 'FeatureCollection', features }));
console.log(`${features.length} Länder → ${out} (${Math.round(fs.statSync(out).size / 1024)} KB)`);
