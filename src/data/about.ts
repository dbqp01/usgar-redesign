import { getCollection } from "astro:content";

// Consumers (AboutSection.astro) read flat `title_<lang>` / `purpose_<lang>`
// keys and `values[].title_<lang>` — keep the flat shape. `values` are sorted
// by the parser's `order` index (JSON array order = business order); `order`
// is stripped so consumers don't see it.
const rawAbout = await getCollection("about");

const entry = rawAbout[0];

if (!entry) {
  throw new Error("about collection is empty");
}

export const aboutData = {
  ...entry.data,
  values: [...entry.data.values]
    .sort((a, b) => a.order - b.order)
    .map(({ order: _order, ...value }) => value),
};
