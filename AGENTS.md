# Content Translation Repository Contract

Content Translation is a general-purpose Jyavani plugin. Keep its runtime,
documentation, tests, commit messages, releases, and pull-request metadata
independent from every private downstream deployment.

- Never name clients, employers, office projects, or their domains.
- Use generic fixtures and terms such as `downstream consumer`.
- Keep site-specific content, acceptance tools, and integration documentation
  in the consuming repository.
- Run every PHP test in `tests/` and lint all PHP files after relevant changes.
