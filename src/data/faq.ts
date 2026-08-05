import { getCollection } from "astro:content";

// file() loader store order is non-deterministic (docs) — sort by the parser's
// `order` index (JSON array order = business order).
const rawFaqs = (await getCollection("faq")).sort((a, b) =>
  a.data.order - b.data.order
);

// Consumers (FAQSection.astro, index.astro FAQPage schema) read flat
// `question_<lang>` / `answer_<lang>` keys — data keeps the flat schema shape.
// `order` is a sort-only field (parser), stripped here so consumers don't see it.
export const faqData = {
  questions: rawFaqs.map(({ data: { order: _order, ...rest } }) => rest),
};
