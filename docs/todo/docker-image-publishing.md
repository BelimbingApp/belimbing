# TODO: Docker Image Publishing

**Status**: Not Ready
**Priority**: Medium
**Target**: v1.0 or later

## Overview

Implement automated Docker image building and publishing to GitHub Container Registry (GHCR) for production and development deployments.

## Why Build Docker Images

### Benefits
- **Easy Deployment**: Pre-built images allow instant deployment without building on target servers
- **Consistency**: Same image runs identically across development, staging, and production
- **Version Control**: Tagged images provide rollback capability and version history
- **Multi-Architecture**: Support for AMD64 and ARM64 (Apple Silicon, AWS Graviton, etc.)
- **CI/CD Integration**: Automated builds on every release and main branch push
- **Distribution**: Easy sharing and deployment across teams and environments

### Use Cases
- Self-hosted production deployments on Linux servers
- Developer onboarding - pull and run without local build
- Testing environments - consistent image for QA
- Staging environments - test exact production image
- Demo deployments - quick setup for presentations
- Team collaboration - share tested images across development teams

## Why GHCR vs Alternatives

### GitHub Container Registry (GHCR)
**Pros:**
- Native GitHub integration - same authentication, permissions as repository
- Free for public repositories
- No rate limiting for authenticated users (vs Docker Hub's 200 pulls/6 hours)
- Unlimited bandwidth and storage for public images
- Multi-architecture support built-in
- GitHub Actions integration with `GITHUB_TOKEN`
- Package visibility tied to repository visibility

**Cons:**
- Less discoverable than Docker Hub
- Smaller ecosystem compared to Docker Hub
- Requires GitHub account for private images

### Alternatives Comparison

**Docker Hub:**
- Pros: Most popular, high discoverability, large ecosystem
- Cons: Rate limiting (200 pulls/6hrs unauthenticated), costs $5/mo for private repos, slower builds

**AWS ECR (Elastic Container Registry):**
- Pros: Tight AWS integration, fast in AWS regions
- Cons: AWS account required, costs $0.10/GB storage, complex IAM setup

**Google Artifact Registry:**
- Pros: GCP integration, multi-format support
- Cons: GCP account required, costs $0.10/GB storage

**Azure Container Registry:**
- Pros: Azure integration, geo-replication
- Cons: Azure account required, costs from $5/mo

**Recommendation**: GHCR is ideal for this project because:
- Open source project benefits from free public hosting
- Team already uses GitHub for repository
- No additional accounts or billing required
- Perfect for GitHub Actions automation
- Aligns with self-hosted philosophy (businesses pull images to their own servers)
- No cloud vendor dependency for image distribution

## Prerequisites Before Publishing

### 1. Test Dockerfiles Locally
- [ ] Build production image: `docker build -f docker/Dockerfile.prod -t test-prod .`
- [ ] Build development image: `docker build -f docker/Dockerfile.dev -t test-dev .`
- [ ] Test running production image
- [ ] Test running development image
- [ ] Verify all services work in containers
- [ ] Test database migrations in container
- [ ] Verify volume mounts work correctly
- [ ] Test environment variable configuration

### 2. Verify Docker Compose Configuration
- [ ] Test `docker/docker-compose.yml` with local builds
- [ ] Verify all profiles (dev/prod) work
- [ ] Test networking between containers
- [ ] Verify health checks function correctly
- [ ] Test SSL certificate generation (Caddy)
- [ ] Confirm port mappings are correct

### 3. Validate Scripts
- [ ] Test `scripts/start-docker.sh` end-to-end
- [ ] Test `scripts/stop-docker.sh` cleanup
- [ ] Verify auto-port detection works
- [ ] Test volume handling and cleanup
- [ ] Confirm migration and admin user creation
- [ ] Test SSL certificate trust setup

### 4. Security Review
- [ ] Review exposed ports in Dockerfiles
- [ ] Audit environment variables and secrets handling
- [ ] Verify file permissions in containers
- [ ] Check for hardcoded credentials
- [ ] Review Caddy TLS configuration
- [ ] Validate health check endpoints don't leak info

### 5. Documentation
- [ ] Document all environment variables
- [ ] Create user guide for pulling and running images
- [ ] Document volume mount requirements
- [ ] List required environment variables for production
- [ ] Add troubleshooting guide
- [ ] Create docker-compose.yml examples for users

### 6. Optimize Image Size
- [ ] Review layer caching strategy
- [ ] Minimize installed packages
- [ ] Use multi-stage builds where possible
- [ ] Target < 500MB for production image
- [ ] Document image size in README

## Sample AI Coding Prompt

When ready to implement, use this prompt with an AI coding assistant:

```
Review the Belimbing project's Docker setup and create a GitHub Actions workflow
for building and publishing Docker images to GHCR.

Context:
- Docker configuration: docker/Dockerfile.prod, docker/Dockerfile.dev, docker/docker-compose.yml
- Startup script: scripts/start-docker.sh (handles local development)
- Stop script: scripts/stop-docker.sh (cleanup and volume management)
- Project name convention: "blb" (used in scripts)
- Frontend domain: defined in docker/.env (FRONTEND_DOMAIN)

Requirements:
1. Build both production (Dockerfile.prod) and development (Dockerfile.dev) images
2. Support multi-architecture builds (linux/amd64, linux/arm64)
3. Publish to ghcr.io/<repo>-belimbing and ghcr.io/<repo>-belimbing-dev
4. Trigger on:
   - Push to main branch
   - Version tags (v*)
   - Manual workflow dispatch
   - Pull requests (build only, don't push)
5. Tag strategy:
   - latest (for main branch)
   - Semver versions (1.0.0, 1.0)
   - Git SHA with branch prefix
   - Branch names
6. Include image size check (<500MB target)
7. Use GitHub Actions cache for faster builds
8. Add proper SPDX license header (AGPL-3.0-only)

Consider:
- The docker-compose.yml uses profiles (dev/prod) - images should match
- Scripts use blb as project name - ensure compatibility
- Both images need to support the environment variables used in start-docker.sh
- SSL certificate handling by Caddy container
- Database and Redis service dependencies
- Volume mounts for storage, logs, and certificates

Output:
- Complete .github/workflows/build-images.yml workflow file
- Follow existing codebase conventions and patterns
- Add inline comments explaining key steps
```

## Implementation Steps

1. Complete all prerequisites above
2. Use the AI prompt to generate initial workflow
3. Test workflow on a feature branch with pull request
4. Verify images build successfully for both architectures
5. Test pulling and running published images locally
6. Update documentation with usage instructions
7. Merge to main and verify auto-publishing works
8. Create first release tag (v1.0.0) and verify semver tagging

## Related Files

- `.github/workflows/build-images.yml` (to be recreated when ready)
- `docker/Dockerfile.prod`
- `docker/Dockerfile.dev`
- `docker/docker-compose.yml`
- `scripts/start-docker.sh`
- `scripts/stop-docker.sh`
- `docs/guides/quickstart.md` (update with image usage)

## Notes

- Deleted premature workflow on 2025-01-02
- Project still in development, not ready for public image distribution
- Focus on local development workflow first
- Consider beta testing with team before public release
