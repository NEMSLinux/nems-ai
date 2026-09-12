# nems-ai
An on-device AI and Smart Notification engine for NEMS Linux by Robbie Ferguson.

![nems-ai Terminal Output](nems-ai_1.8.008.gif)

`nems-ai` is an optional, fully local AI engine designed to run directly on your NEMS Server. It does not connect to external servers, cloud APIs, or third-party services. All data processing occurs 100% on-device and completely private to your local network.

**nems-ai is not installed by default and will never install itself.** You must explicitly choose to install it on your system by running:

```bash
sudo apt update && sudo apt install nems-ai

```

**Current Status:** Proof of Concept / Active Development

**Requirement:** NEMS Linux 1.8 or higher

---

# Automatic Hardware Optimization

When installed, `nems-ai` automatically detects your system's available RAM and selects the optimal Large Language Model (LLM) tier to ensure fast synthesis without competing with Nagios Core for system resources:

| System Memory | LLM Model | Target Systems & Characteristics |
| --- | --- | --- |
| **< 4 GB RAM** | `llama3.2:1b` | **Ultra-Lightweight:** Low RAM footprint (~0.9 GB) designed to run smoothly on lower-spec hardware without impacting core monitoring. |
| **4 GB – 16 GB RAM** | `llama3.2:3b` | **Balanced:** Enhanced contextual reasoning (~2.0 GB footprint) for Raspberry Pi 4/5 and mid-range systems. |
| **16 GB+ RAM** | `llama3.1:8b` | **Enterprise Grade:** High-performance NOC synthesis (~4.8 GB footprint) providing deep root-cause insight on Enterprise servers and VM hosts. |

---

# NEMS API Integration

When `nems-ai` is installed, it registers the `/nems-api/nems-ai` endpoint. Real-time interfaces (such as NEMS Central Command) route raw incident and recovery alerts through this endpoint to generate concise, natural-language voice and display notifications.

If `nems-ai` is not installed on your system, the endpoint safely remains inactive and system dashboards automatically use standard status strings.

For complete endpoint specifications, payload parameters, and JSON examples, see the [NEMS API Documentation](https://docs.nemslinux.com/en/latest/advanced/nems-api.html).

---

# Future Features

* Ability to query `nems-ai` about the real-time state of any monitored node (e.g., *"How long has my web server been down?"* or *"Which host has the highest CPU usage?"*).
* Advanced diagnostic suggestions during multi-system outages (e.g., identifying when network latency or bad IP addresses trigger cascading service check failures).
* Improved NEMS Linux documentation knowledge base integration to ensure recommendations strictly follow NEMS Web Admin workflows rather than raw Nagios Core CLI commands.

