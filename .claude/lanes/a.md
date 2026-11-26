# Ecosystem Foundation
Dev: tvhoang91
Build: lane-a

Core's side and the proof that Yay plugins work together safely in a workflow (docs/extensions-roadmap.md). Each plugin's real extension is its owner's task, from the brief this lane hands over.

- brainstorm: extensions roadmap, PR #47
- prepare email stack: decide its opens, write its plan, until it is ready to build
- email-stack: core changes (email mark, delivery status by the mark), fixture extensions acting as YayMail, YayMagic, YaySMTP, scenario tests for the group 1 matrix, CI job that runs each owner's extension against develop
- hand group 1 briefs to the YayMail, YayMagic and YaySMTP owners
- Review email stack
- prepare value stack: decide its opens, write its plan, until it is ready to build
- value-stack: core changes (fields that declare their meta key, view facades, pickers), fixture extensions acting as YayPricing, YayWholesale, YayLoyalty, scenario tests for the group 2 matrix
- hand group 2 briefs to the YayPricing, YayWholesale and YayLoyalty owners
- Review value stack
