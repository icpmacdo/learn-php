# learn-php

A hands-on PHP learning repo. Each lesson is a small, working project — starting with a Symfony server — built up incrementally to cover modern backend development end to end.

## Tech stack

- **Language & framework**: PHP 8, Symfony
- **Data**: MySQL, Redis
- **APIs**: RESTful JSON APIs
- **Infrastructure**: Docker, Ansible, AWS
- **Tooling**: Git, CI/CD (Jenkins)
- **Testing**: Codeception
- **Architecture**: MVC, SOLID, DDD, microservices, event-driven design
- **Security**: authentication, SQL injection & XSS mitigation

## Structure

The curriculum has five parts — two warm-up projects, then one e-commerce order system that evolves from DDD monolith to event-driven services to a production deployment on a single Lightsail server. See [docs/curriculum.md](docs/curriculum.md) for the overview and [docs/specs/](docs/specs/) for the one-page spec of each part.

Each part lives in its own directory (`link-shortener/`, ...), self-contained with its own README.

## Running the projects

Every part ships with a Docker Compose setup so nothing needs to be installed globally. See each part's README for specifics.
