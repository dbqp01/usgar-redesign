# Graph Report - .  (2026-07-14)

## Corpus Check
- 282 files · ~420,572 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 355 nodes · 481 edges · 36 communities (30 shown, 6 thin omitted)
- Extraction: 99% EXTRACTED · 1% INFERRED · 0% AMBIGUOUS · INFERRED: 7 edges (avg confidence: 0.67)
- Token cost: 0 input · 0 output

## Community Hubs (Navigation)
- Tina CMS Generated Types
- Astro Static Assets & Logos
- Code Audit Security Engine
- Channex Integration & Database
- Rooms & Booking Pages
- Package Dependency Config
- Astro Base Configuration
- Tina CMS JS Queries
- Payment Checkout & Mock Flow
- Shadcn UI Data Table Example
- Web Quality Audit Runner
- TypeScript Configuration
- Image Compression Script
- Shadcn UI Form Pattern
- Tina CMS SDK Queries
- Code Audit Helper Script
- Security Audit Scripts
- Shadcn UI Setup Verifier
- Git Commit Hooks
- Tina CMS Client Wrapper

## God Nodes (most connected - your core abstractions)
1. `../../layouts/Layout.astro` - 21 edges
2. `useTranslations()` - 17 edges
3. `../../components/RoomCard.astro` - 14 edges
4. `../book.astro` - 14 edges
5. `../../components/RoomDetail.astro` - 12 edges
6. `../components/Navbar.astro` - 8 edges
7. `ConfigLoader` - 7 edges
8. `ReportGenerator` - 7 edges
9. `scripts` - 7 edges
10. `getDbConnection()` - 7 edges

## Surprising Connections (you probably didn't know these)
- `../components/MapSection.astro` --dynamic_import--> `leaflet/dist/leaflet.css`  [EXTRACTED]
  src/components/MapSection.astro → leaflet/dist/leaflet.css
- `../components/MapSection.astro` --dynamic_import--> `../assets/logos/Logo usgar isotipo.png`  [EXTRACTED]
  src/components/MapSection.astro → src/assets/logos/Logo usgar isotipo.png
- `updatePricing()` --calls--> `daysBetween()`  [EXTRACTED]
  src/pages/book.astro → src/utils/helpers.ts
- `updatePricing()` --calls--> `translateBeds()`  [EXTRACTED]
  src/pages/book.astro → src/utils/helpers.ts

## Import Cycles
- None detected.

## Communities (36 total, 6 thin omitted)

### Community 0 - "Tina CMS Generated Types"
Cohesion: 0.02
Nodes (98): About, AboutConnection, AboutConnectionEdges, AboutConnectionQuery, AboutConnectionQueryVariables, AboutFilter, AboutMutation, AboutPartsFragment (+90 more)

### Community 1 - "Astro Static Assets & Logos"
Cohesion: 0.07
Nodes (42): ../assets/hotel/hero-slide-cusco.jpg, ../assets/hotel/hero-slide-matrimonial.jpg, ../assets/hotel/hero-slide-patio.jpg, ../assets/hotel/hero-slide-recepcion.jpg, ../assets/logos/Logo usgar isotipo.png, ../assets/logos/Logo usgar morado.png, ../assets/logos/Logo usgar.png, ../data/attractions (+34 more)

### Community 2 - "Code Audit Security Engine"
Cohesion: 0.14
Nodes (11): CodeScanner, ConfigLoader, ControlDetector, Finding, main(), OperationContext, 检查上下文中是否存在必需的安全控制，返回缺失的控制列表, ReportGenerator (+3 more)

### Community 3 - "Channex Integration & Database"
Cohesion: 0.13
Nodes (10): ChannexSync, confirmBooking(), getBooking(), getBookings(), getDbConnection(), getDbPath(), getEnvValue(), saveBooking() (+2 more)

### Community 4 - "Rooms & Booking Pages"
Cohesion: 0.15
Nodes (11): ../../data/rooms, ../../utils/helpers, ../../components/RoomDetail.astro, Locale, ../book.astro, selectRoomCard(), ../../book/success.astro, API_BASE_URL (+3 more)

### Community 5 - "Package Dependency Config"
Cohesion: 0.10
Nodes (20): allowScripts, esbuild@0.28.1, sharp@0.35.3, devDependencies, puppeteer, @types/leaflet, typescript, name (+12 more)

### Community 6 - "Astro Base Configuration"
Cohesion: 0.12
Nodes (17): astro, @astrojs/sitemap, dependencies, astro, @astrojs/sitemap, leaflet, sharp, tailwindcss (+9 more)

### Community 7 - "Tina CMS JS Queries"
Cohesion: 0.18
Nodes (13): AboutConnectionDocument, AboutDocument, AboutPartsFragmentDoc, ExperimentalGetTinaClient(), ExploreConnectionDocument, ExploreDocument, ExplorePartsFragmentDoc, FaqConnectionDocument (+5 more)

### Community 8 - "Payment Checkout & Mock Flow"
Cohesion: 0.29
Nodes (6): ../../book/mock-payment.astro, approveSpinner, approveText, btnDecline, newBtnApprove, newBtnDecline

### Community 9 - "Shadcn UI Data Table Example"
Cohesion: 0.40
Nodes (3): columns, data, User

### Community 10 - "Web Quality Audit Runner"
Cohesion: 0.60
Nodes (3): analyze_html(), fail(), analyze.sh script

### Community 11 - "TypeScript Configuration"
Cohesion: 0.40
Nodes (4): astro/tsconfigs/strict, compilerOptions, paths, extends

### Community 12 - "Image Compression Script"
Cohesion: 0.60
Nodes (4): compressImage(), files, run(), SUPPORTED_EXTENSIONS

### Community 14 - "Tina CMS SDK Queries"
Cohesion: 0.83
Nodes (4): ExperimentalGetTinaClient(), generateRequester(), getSdk(), queries()

## Knowledge Gaps
- **169 isolated node(s):** `User`, `data`, `columns`, `formSchema`, `FormValues` (+164 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **6 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `../../layouts/Layout.astro` connect `Astro Static Assets & Logos` to `Payment Checkout & Mock Flow`, `Rooms & Booking Pages`?**
  _High betweenness centrality (0.021) - this node is a cross-community bridge._
- **Why does `../../book/mock-payment.astro` connect `Payment Checkout & Mock Flow` to `Astro Static Assets & Logos`?**
  _High betweenness centrality (0.008) - this node is a cross-community bridge._
- **Why does `dependencies` connect `Astro Base Configuration` to `Package Dependency Config`?**
  _High betweenness centrality (0.007) - this node is a cross-community bridge._
- **What connects `User`, `data`, `columns` to the rest of the system?**
  _169 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `Tina CMS Generated Types` be split into smaller, more focused modules?**
  _Cohesion score 0.02 - nodes in this community are weakly interconnected._
- **Should `Astro Static Assets & Logos` be split into smaller, more focused modules?**
  _Cohesion score 0.06954997077732321 - nodes in this community are weakly interconnected._
- **Should `Code Audit Security Engine` be split into smaller, more focused modules?**
  _Cohesion score 0.1350806451612903 - nodes in this community are weakly interconnected._