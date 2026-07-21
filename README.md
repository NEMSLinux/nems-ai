# nems-ai
On-device AI Copilot and Smart Notification engine for NEMS Linux.

![nems-ai Terminal Output](nems-ai_1.8.008.gif)

Current Status: Proof of concept

Requires NEMS Linux 1.8.

In its current form, nems-ai downloads the entire content of the NEMS Documentation site and ingests it as its knowledge, combined with the Llama 3.2-1B LLM.

nems-ai is designed to be run directly on the NEMS Server; it doesn't use any cloud APIs or "big tech" companies. It doesn't share anything with anyone, and simply runs on your local NEMS Server if you call it.

nems-ai is not installed by default and will not install itself. You must install it yourself with `apt install nems-ai`
