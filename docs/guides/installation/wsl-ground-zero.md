# Windows onboarding from ground zero

Acknowledgement: These steps were developed in collaboration with the wonderful people from SB Tape Group.
Please raise a PR if you find mistakes on this guide.

## Prerequisite
1. Windows 10 version 2004 (Build 19041) or later, or Windows 11. Virtualization must be enabled in BIOS/UEFI.
2. An account in GitHub.
3. AI harness: Codex, Claude, Cursor, Grok Bot, Antigravity, Amp, Warp, Zed, Kiro, Trae, OpenCode, Pi.

## Part 1 - Launch Belimbing in a browser
### Install WSL2
Key concepts: VM, WSL, Windows Terminal, PowerShell, Bash, Linux, Ubuntu, GitHub, Open Source, MIT License

1. [Win] Microsoft Store → install latest **PowerShell**
2. [Win] Open PowerShell **as Administrator**
3. [PS] Install WSL + Ubuntu: `wsl --install -d Ubuntu`
4. **Reboot**
5. [Win] After reboot, Ubuntu finishes installing, start PowerShell if it's not already open
6. [Win Terminal:Ubuntu] Create your Linux username and password when prompted, note that the cursor stays in-place on entering your password
7. [Win Terminal:Ubuntu] Open Ubuntu: open the tab dropdown: `+` → Ubuntu
8. [Ubuntu] Install GitHub CLI:

```bash
(type -p wget >/dev/null || (sudo apt update && sudo apt install wget -y)) \
	&& sudo mkdir -p -m 755 /etc/apt/keyrings \
	&& out=$(mktemp) && wget -nv -O$out https://cli.github.com/packages/githubcli-archive-keyring.gpg \
	&& cat $out | sudo tee /etc/apt/keyrings/githubcli-archive-keyring.gpg > /dev/null \
	&& sudo chmod go+r /etc/apt/keyrings/githubcli-archive-keyring.gpg \
	&& sudo mkdir -p -m 755 /etc/apt/sources.list.d \
	&& echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/githubcli-archive-keyring.gpg] https://cli.github.com/packages stable main" | sudo tee /etc/apt/sources.list.d/github-cli.list > /dev/null \
	&& sudo apt update \
	&& sudo apt install gh -y
```
9. [Ubuntu] Login to GutHub: `gh auth login`
10. [Ubuntu] ... Finish the authentication ...
   - Use the defaults, i.e. web-based browser flow
   - Copy a one-time code like "7CDF-8959" to paste on the browser
   - Check authentication: `gh auth status`

### Clone Belimbing
Key concepts: Source Code, TUI, Bash, Git, CLI: `ll`, `cd`, `mkdir`, `git`, `gh`

1. [Ubuntu] Go to your home directory: `cd ~`
2. [Ubuntu:~$] Make folder: `mkdir -p repo`
3. [Ubuntu:~$] Enter it: `cd repo`
4. [Ubuntu:~/repo$] Clone: `gh repo clone BelimbingApp/belimbing belimbing`
5. [Ubuntu:~/repo/belimbing$] Enter project: `cd belimbing`

### Setup Belimbing
Key concepts: Web App, Dependencies, Web Server, Database, Runtime

1. [Ubuntu:~/repo/belimbing$] `scripts/setup.sh`
2. [Ubuntu:~/repo/belimbing$] Use the default options and press Enter to continue.
3. ... Finish the setup process ...
    - Important: Take note of the IP address and hostname to use
    - Setup company, name, email, password
    - If successful, the web server will be started

### Add host entry
Key concepts: DNS, Hosts File, IP address

1. [Win] Windows Start → type `notepad` → right-click → **Run as administrator**
2. [Notepad] File → Open → `C:\Windows\System32\drivers\etc\hosts`
3. [Notepad] Change the file filter to **All Files** to see it
4. [Notepad] Add the IP and hostname from the setup to the hosts file:
    `172.25.114.176 local.blb.lara`
     *(Replace `172.25.114.176` with your actual WSL2 IP address from setup.sh)*
    - Alternatively, get the WSL2 IP address: `hostname -I | awk '{print $1}'`
5. [Notepad] Save and close

### Launch Belimbing
Key concepts: Client, Request - Response

1. [Ubuntu:~/repo/belimbing$] Start the web server:  `scripts/start-app.sh`
2. [Win] Open browser: `https://local.blb.lara`

## Part 2 - Create a development environment
### Git Crash Course
Key concepts: Remotes, Branches, Default Branch, Commits, Pull, Push, Issues, Pull Requests, CI/CD

- Remotes — nicknames for GitHub copies of the repo:
   - `origin` — company owned repo
   - `upstream` — the main BelimbingApp repo
- Branches — parallel lines of work:
   - `main` or `master` — the shared `<default-branch>`
   - `feature/new-feature` — your working branch for a change
- Commits — save a snapshot of your changes:
   - Stage: `git add .`
   - Commit: `git commit -m "Add new feature"`
- Pull — download others' latest changes: `git pull`
- Push — upload your commits to GitHub: `git push`
- Issues — open a task/bug on GitHub: `gh issue create`
- Pull Requests — ask to merge your branch: `gh pr create`
- CI/CD — continuous integration/continuous deployment:
- GitHub Actions — automate your workflow
- GitHub Pages — host your static websites
- GitHub Packages — store and share your packages

### Connect to Company GitHub & Setup Remotes
Key concepts: GitHub Settings, Private Repository, Public Repository

1. [Ubuntu:~/repo/belimbing$] Connect to company GitHub: `gh auth login`
2. [Ubuntu:~/repo/belimbing$] Enter your username and password
3. [Ubuntu:~/repo/belimbing$] Setup remotes:
```bash
git remote add upstream https://github.com/BelimbingApp/belimbing.git
git remote set-url origin https://github.com/{company_org}/{belimbing}.git
```
Note: replace the  last command with GitHub organization and name of the repo.
4. Install Domains and company extension

### Development Environment
Key concepts: IDE, AI Harness, Models, Thinking Efforts

1. IDEs: Cursor, VS Code, Zed, Antigravity
2. AI Harnesses: Codex, Claude, Cursor, Grok Bot, Antigravity, Amp, Warp
3. ... Install your favorite IDE and AI Harness ...

### Development Workflow
Key concepts: Development, Staging, Production, Git Promotion, PR

- Development: your local machine; break things safely, use sample data
- Staging: a dress rehearsal; test before go-live, with realistic data/config
- Production: the live app

#### Development
1. Create a new branch (`branch-a`) from `<default-branch>`
2. Checkout `branch-a`
3. Build, migrate, test
4. Commit changes to `branch-a`
5. Push and create a PR
6. PR: review, approval, merge (to `<default-branch>`)
7. Optional: platform contributions PRs target upstream (`BelimbingApp/belimbing`)

#### Staging
1. Deploy to staging: pull `<default-branch>`, update dependencies, run migrations, reload frontend
2. test in staging

#### Production
1. Deploy to production: pull `<default-branch>`, update dependencies, run migrations, reload frontend
2. panic button, rollback to previous version

### Update Belimbing in Company

#### Development
1. Fetch from upstream
2. Checkout <default-branch> → pull origin
3. Create a new branch `branch-b`
4. Merge `upstream/main` to `branch-b`
5. Update dependencies and test in development
6. Optional commit changes to `branch-b`
7. Push and create a PR
8. PR: review, approval, merge (to `<default-branch>`)

#### Staging && Production
Same as Development Workflow
