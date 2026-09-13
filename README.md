# nems-ai
An on-device AI and Smart Notification engine for NEMS Linux by Robbie Ferguson.

![nems-ai Terminal Output](nems-ai_1.8.008.gif)

nems-ai downloads the entire content of the NEMS Documentation site and ingests it as its knowledge.

nems-ai is designed to be run directly on the NEMS Server; it doesn't connect to any servers, use any cloud APIs or "big tech" infrastructure or service. It doesn't share anything with anyone, and simply runs on your local NEMS Server if you call it.

**nems-ai is not installed by default and will not install itself.** You must install it yourself with `sudo apt update && sudo apt install nems-ai`

**Current Status:** Proof of concept / Active Development

Requires NEMS Linux 1.8 or higher.

Automatic Hardware & Model Optimization
=======================================

When installed, `nems-ai` automatically inspects your server hardware, detecting available system memory and checking for dedicated graphics hardware (GPU acceleration) to choose the ideal Large Language Model (LLM) tier.

On CPU-only systems and virtual machines, `nems-ai` automatically caps background CPU thread consumption. This ensures quick response times without bogging down host processor cores or impacting core monitoring checks.

| Hardware Profile | Model Selected | Target Systems & Characteristics |
| :--- | :--- | :--- |
| **GPU Accelerated**<br>*(16 GB+ RAM with dedicated GPU)* | `llama3.1:8b` | **Enterprise Grade:** High-performance NOC synthesis providing deep root-cause insight on GPU-accelerated server hardware. |
| **Standard Systems & VMs**<br>*(CPU-Only or 4 GB – 16 GB+ RAM)* | `llama3.2:3b` | **Balanced & Efficient:** Optimized causal reasoning with automatic CPU thread limiting for Raspberry Pi 4/5, virtual machines, and CPU-only systems. |
| **Low-Memory Hardware**<br>*(< 4 GB RAM)* | `llama3.2:1b` | **Ultra-Lightweight:** Minimal memory footprint (~0.9 GB) designed to run smoothly on lower-spec hardware without competing with Nagios Core. |

NEMS API Integration
====================

When `nems-ai` is installed, it registers the `/nems-api/nems-ai` endpoint. Real-time interfaces (such as NEMS Central Command) route raw incident and recovery alerts through this endpoint to generate concise, natural-language voice and display notifications.

If `nems-ai` is not installed on your system, the endpoint safely remains inactive and system dashboards automatically use standard status strings.

For complete endpoint specifications, payload parameters, and JSON examples, see the [NEMS API Documentation](https://docs.nemslinux.com/en/latest/advanced/nems-api.html).

Future features
===============

* Ability to ask nems-ai about the state of any of your monitored systems. E.g., "How long has my web server been down for?" or "How much disk space is left on my domain controller?"
* nems-ai to optionally power notifications, making them much more detailed and unique. E.g., "The web server at location 3 has been flapping for a few hours. I notice the CPU usage has been really high during this time, and it looks like there's a big powershell process running, so it may be good to remote in and have a look."
* A better understanding of NEMS documentation as source of truth. Currently, the LLM gets confused between NEMS Linux and Nagios Core, so provides CLI check commands rather than following the NEMS configuration flow.
