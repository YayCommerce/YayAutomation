# Ecosystem Foundation
Dev: tvhoang91
Build: lane-a

Core's side and the proof that Yay plugins work together safely in a workflow (docs/extensions-roadmap.md). The lane ends by putting an extension scaffold in each product's repo; filling it is the product team's work.

- brainstorm: extensions roadmap, PR #47
- prepare email stack: decide its opens, write its plan, until it is ready to build
- email-stack: core changes (email mark, delivery status by the mark), fixture extensions acting as YayMail, YayMagic, YaySMTP, scenario tests for the group 1 matrix, CI job that runs each product's extension against develop
- prepare value stack: decide its opens, write its plan, until it is ready to build
- value-stack: core changes (fields that declare their meta key, view facades, pickers), fixture extensions acting as YayPricing, YayWholesale, YayLoyalty, scenario tests for the group 2 matrix
- Review ecosystem foundation
- extension scaffolds: in the YayMail, YayMagic, YaySMTP, YayPricing, YayWholesale and YayLoyalty repos, one PR each, wired to the CI job
