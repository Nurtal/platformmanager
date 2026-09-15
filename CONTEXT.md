# Platform-Manager

Platform-Manager is a database web application used by scientific core facilities to manage
their day-to-day operations: bookable equipment, user access, service requests, invoicing and
shared documents. A single installation hosts several independent facilities side by side.

## Language

### Organisation

**Space**:
An independent facility hosted by the installation, owning its own resources, members,
menus and billing. Every piece of business data belongs to exactly one Space.
_Avoid_: lab, team, group, platform, project

**Module**:
A self-contained functional area (booking, invoices, helpdesk...) enabled installation-wide
by configuration, and exposed inside a Space through that Space's menus.
_Avoid_: plugin, extension, component

### Authorisation

The two levels below are independent scales that the database confusingly stores under the
same column name (`status`). The distinction matters whenever access rights are discussed.

**Status**:
A User's privilege level across the whole installation: `user` or `admin`. An `admin` Status
overrides any Role within any Space.
_Avoid_: global role, app role, user level

**Role**:
A User's privilege level inside one Space: `visitor`, `user`, `manager` or `admin`. A User
holds a different Role in each Space they belong to, and none in the others.
_Avoid_: space status, membership level, permission

**Impersonation**:
An administrator acting under another User's identity for the duration of a session, while
the originating identity is retained. An impersonated session is confined to a single Space.
_Avoid_: sudo, masquerade, switch user
