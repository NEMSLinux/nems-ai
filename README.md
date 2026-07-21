# nems-ai
An on-device AI and Smart Notification engine for NEMS Linux by Robbie Ferguson.

![nems-ai Terminal Output](nems-ai_1.8.008.gif)

nems-ai downloads the entire content of the NEMS Documentation site and ingests it as its knowledge.

nems-ai is designed to be run directly on the NEMS Server; it doesn't connect to any servers, use any cloud APIs or "big tech" infrastructure or service. It doesn't share anything with anyone, and simply runs on your local NEMS Server if you call it.

nems-ai is not installed by default and will not install itself. You must install it yourself with `apt install nems-ai`

Current Status: Proof of concept

Requires NEMS Linux 1.8.

Future features
===============

* Ability to ask nems-ai about the state of any of your monitored systems. E.g., "How long as my web server been down for?" or "How much disk space is left on my domain controller?"
* nems-ai to optionally power notifications, making them much more detailed and unique. E.g., "The web server at location 3 has been flapping for a few hours. I notice the CPU usage has been really high during this time, and it looks like there's a big powershell process running, so it may be good to remote in and have a look."
* A better understanding of NEMS documentation as source of truth. Currently, the LLM gets confused between NEMS Linux and Nagios Core, so provides CLI check commands rather than following the NEMS configuration flow.
