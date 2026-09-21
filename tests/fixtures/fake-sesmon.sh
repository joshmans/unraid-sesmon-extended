#!/bin/sh
# Stand-in for the sesmon binary: "check" rejects files that contain BADKEY, "test" always passes.
case "$1" in
  --version) echo "sesmon version fake" ;;
  check) if grep -q BADKEY "$2"; then echo "failure parsing YAML: field BADKEY not found in type main.DeviceYAML"; exit 1; fi ;;
  test) echo "SAS address resolved (fake)"; echo "Warning: SAS address [0x1] came up for multiple devices (ignoring it for address lookups)" ;;
esac
