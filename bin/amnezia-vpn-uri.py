#!/usr/bin/env python3
"""Build vpn:// import URI for Amnezia VPN app (AmneziaWG)."""
import base64
import json
import struct
import sys
import zlib


def encode_vpn_uri(conf_text, h1, h2, h3, h4, jc, jmin, jmax, s1, s2, s3, s4,
                   i1, port, hostname, client_ip, client_priv, server_pub, allowed_ips):
    raw = conf_text.rstrip('\n')
    ips = [x.strip() for x in allowed_ips.split(',') if x.strip()]
    if not ips:
        ips = ['0.0.0.0/0']

    inner = {
        'H1': h1, 'H2': h2, 'H3': h3, 'H4': h4,
        'Jc': jc, 'Jmin': jmin, 'Jmax': jmax,
        'S1': s1, 'S2': s2, 'S3': s3, 'S4': s4,
        'allowed_ips': ips,
        'client_ip': client_ip,
        'client_priv_key': client_priv,
        'config': raw,
        'hostName': hostname,
        'mtu': '1280',
        'persistent_keep_alive': '33',
        'port': int(port),
        'server_pub_key': server_pub,
    }
    if i1:
        inner['I1'] = i1
        inner['I2'] = inner['I3'] = inner['I4'] = inner['I5'] = ''

    inner_json = json.dumps(inner, separators=(',', ':'))
    outer = {
        'containers': [{
            'awg': {
                'isThirdPartyConfig': True,
                'last_config': inner_json,
                'port': str(port),
                'protocol_version': '2',
                'transport_proto': 'udp',
            },
            'container': 'amnezia-awg',
        }],
        'defaultContainer': 'amnezia-awg',
        'description': 'AWG Server',
        'dns1': '1.1.1.1',
        'dns2': '1.0.0.1',
        'hostName': hostname,
    }
    outer_json = json.dumps(outer, separators=(',', ':'))
    outer_bytes = outer_json.encode('utf-8')
    compressed = zlib.compress(outer_bytes)
    payload = struct.pack('>I', len(outer_bytes)) + compressed
    b64 = base64.b64encode(payload).decode('ascii').rstrip('=')
    b64 = b64.translate(str.maketrans('+/', '-_'))
    return 'vpn://' + b64


def main():
    if len(sys.argv) != 20:
        sys.stderr.write('usage: amnezia-vpn-uri.py <conf-file> h1 h2 h3 h4 jc jmin jmax '
                         's1 s2 s3 s4 i1 port hostname client_ip client_priv server_pub allowed_ips\n')
        sys.exit(1)
    conf_path = sys.argv[1]
    args = sys.argv[2:]
    with open(conf_path, 'r', encoding='utf-8') as fh:
        conf_text = fh.read()
    uri = encode_vpn_uri(conf_text, *args)
    print(uri, end='')


if __name__ == '__main__':
    main()
