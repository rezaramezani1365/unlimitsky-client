#!/usr/bin/env python3
"""Amnezia VPN / AmneziaWG import payloads (tested against official app formats)."""
import base64
import json
import re
import struct
import sys
import zlib

VPN_MAGIC = 0x07C00100
AWG_QR_MAGIC = 0x07C00200


def urlsafe_b64(data: bytes) -> str:
    return base64.urlsafe_b64encode(data).decode('ascii').rstrip('=')


def parse_conf(conf_text: str) -> dict:
    out = {
        'priv': '', 'pub': '', 'psk': '', 'address': '', 'endpoint_host': '',
        'endpoint_port': 51821, 'allowed_ips': [], 'dns': [], 'mtu': 1280,
        'keepalive': 25, 'params': {},
    }
    section = ''
    for line in conf_text.splitlines():
        line = line.strip()
        if not line or line.startswith('#'):
            continue
        if line.startswith('[') and line.endswith(']'):
            section = line[1:-1].lower()
            continue
        if '=' not in line:
            continue
        key, val = [x.strip() for x in line.split('=', 1)]
        kl = key.lower()
        if kl == 'privatekey':
            out['priv'] = val
        elif kl == 'publickey' and section == 'peer':
            out['pub'] = val
        elif kl in ('presharedkey', 'presharedkey'):
            out['psk'] = val
        elif kl == 'address':
            out['address'] = val
        elif kl == 'endpoint':
            m = re.match(r'^\[?([^\]]+)\]?:([0-9]{1,5})$', val)
            if m:
                out['endpoint_host'] = m.group(1)
                out['endpoint_port'] = int(m.group(2))
        elif kl == 'allowedips':
            out['allowed_ips'] = [x.strip() for x in val.split(',') if x.strip()]
        elif kl == 'dns':
            out['dns'] = [x.strip() for x in re.split(r'[,\s]+', val) if x.strip()]
        elif kl == 'mtu':
            try:
                out['mtu'] = int(val)
            except ValueError:
                pass
        elif kl == 'persistentkeepalive':
            try:
                out['keepalive'] = int(val)
            except ValueError:
                pass
        elif key in {'H1', 'H2', 'H3', 'H4', 'I1', 'I2', 'I3', 'I4', 'I5',
                     'Jc', 'Jmin', 'Jmax', 'S1', 'S2', 'S3', 'S4'}:
            if val:
                out['params'][key] = val
    if not out['allowed_ips']:
        out['allowed_ips'] = ['0.0.0.0/0', '::/0']
    return out


def build_envelope(conf_text: str, hostname: str = '', description: str = 'AWG Server') -> dict:
    conf_text = conf_text.rstrip('\n') + '\n'
    p = parse_conf(conf_text)
    host = hostname or p['endpoint_host'] or ''
    port = p['endpoint_port']
    dns1 = p['dns'][0] if p['dns'] else '1.1.1.1'
    dns2 = p['dns'][1] if len(p['dns']) > 1 else '1.0.0.1'
    client_ip = re.sub(r'/(\d{1,2})$', '', p['address'] or '')

    awg_top = {k: str(v) for k, v in p['params'].items()}
    last = {
        **{k: str(awg_top.get(k, '')) for k in (
            'H1', 'H2', 'H3', 'H4', 'I1', 'I2', 'I3', 'I4', 'I5',
            'Jc', 'Jmin', 'Jmax', 'S1', 'S2', 'S3', 'S4')},
        'allowed_ips': p['allowed_ips'],
        'client_ip': client_ip,
        'client_priv_key': p['priv'],
        'config': conf_text.rstrip('\n'),
        'hostName': host,
        'mtu': str(p['mtu']),
        'persistent_keep_alive': str(p['keepalive']),
        'port': port,
        'psk_key': p['psk'],
        'server_pub_key': p['pub'],
    }
    # Drop empty I2-I5 from last_config JSON (breaks some parsers if present)
    for k in ('I2', 'I3', 'I4', 'I5'):
        if not last.get(k):
            last.pop(k, None)

    awg_block = {
        'isThirdPartyConfig': True,
        'last_config': json.dumps(last, separators=(',', ':'), ensure_ascii=False),
        'port': str(port),
        'protocol_version': '2',
        'transport_proto': 'udp',
        **{k: v for k, v in awg_top.items() if v and k not in ('I2', 'I3', 'I4', 'I5')},
    }

    return {
        'containers': [{
            'awg': awg_block,
            'container': 'amnezia-awg',
        }],
        'defaultContainer': 'amnezia-awg',
        'description': description,
        'dns1': dns1,
        'dns2': dns2,
        'hostName': host,
    }


def encode_vpn_uri(conf_text: str, hostname: str = '') -> str:
    envelope = build_envelope(conf_text, hostname)
    json_bytes = json.dumps(envelope, separators=(',', ':'), ensure_ascii=False).encode('utf-8')
    compressed = zlib.compress(json_bytes, 9)
    payload = struct.pack('>I', len(json_bytes)) + compressed
    return 'vpn://' + urlsafe_b64(payload)


def encode_vpn_qr_payload(conf_text: str, hostname: str = '') -> str:
    """QR for Amnezia VPN app — base64url only (no vpn:// prefix)."""
    envelope = build_envelope(conf_text, hostname)
    json_bytes = json.dumps(envelope, indent=4, ensure_ascii=False).encode('utf-8')
    compressed = zlib.compress(json_bytes, 9)
    header = struct.pack('>III', VPN_MAGIC, len(compressed) + 4, len(json_bytes))
    return urlsafe_b64(header + compressed)


def encode_awg_qr_payload(conf_text: str) -> str:
    """QR for AmneziaWG app — magic header + raw .conf (not plain text QR)."""
    conf_bytes = conf_text.rstrip('\n').encode('utf-8')
    header = struct.pack('>II', AWG_QR_MAGIC, len(conf_bytes))
    return urlsafe_b64(header + conf_bytes)


def main():
    if len(sys.argv) < 3:
        sys.stderr.write(
            'usage: amnezia-vpn-uri.py <mode> <conf-file> [hostname]\n'
            '  mode: vpn_uri | vpn_qr | awg_qr | all\n'
        )
        sys.exit(1)
    mode = sys.argv[1]
    conf_path = sys.argv[2]
    hostname = sys.argv[3] if len(sys.argv) > 3 else ''
    with open(conf_path, 'r', encoding='utf-8') as fh:
        conf_text = fh.read()

    if mode == 'vpn_uri':
        print(encode_vpn_uri(conf_text, hostname), end='')
    elif mode == 'vpn_qr':
        print(encode_vpn_qr_payload(conf_text, hostname), end='')
    elif mode == 'awg_qr':
        print(encode_awg_qr_payload(conf_text), end='')
    elif mode == 'all':
        print(json.dumps({
            'vpn_uri': encode_vpn_uri(conf_text, hostname),
            'vpn_qr': encode_vpn_qr_payload(conf_text, hostname),
            'awg_qr': encode_awg_qr_payload(conf_text),
        }, ensure_ascii=False), end='')
    else:
        sys.stderr.write('unknown mode\n')
        sys.exit(1)


if __name__ == '__main__':
    main()
