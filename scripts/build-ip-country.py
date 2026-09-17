"""Compile a locally downloaded DB-IP Country Lite CSV into an offline search index.

Usage: python build-ip-country.py dbip-country-lite-YYYY-MM.csv.gz country.bin
Keep the input provenance/checksum and CC BY 4.0 attribution in operator records.
No addresses are sent to a network service. Output is replaced atomically.
"""
import csv,gzip,ipaddress,os,re,struct,sys,tempfile
from pathlib import Path

source,target=map(Path,sys.argv[1:])
assert re.fullmatch(r'dbip-country-lite-\d{4}-\d{2}\.csv\.gz',source.name)
records={4:bytearray(),6:bytearray()};counts={4:0,6:0};ends={4:None,6:None}
with gzip.open(source,'rt',encoding='utf-8',newline='') as stream:
    for row in csv.reader(stream):
        assert len(row)==3
        start,end=map(ipaddress.ip_address,row[:2]);country=row[2]
        assert start.version==end.version and start<=end and re.fullmatch('[A-Z]{2}',country)
        family=start.version
        assert ends[family] is None or start>ends[family], 'Unsorted or overlapping source range'
        ends[family]=end;records[family].extend(start.packed+end.packed+country.encode('ascii'));counts[family]+=1
assert counts[4] and counts[6]
target.parent.mkdir(parents=True,exist_ok=True)
fd,name=tempfile.mkstemp(prefix='.country-',dir=target.parent)
try:
    with os.fdopen(fd,'wb') as stream:
        stream.write(b'SENSEIP1'+struct.pack('!II',counts[4],counts[6])+records[4]+records[6]);stream.flush();os.fsync(stream.fileno())
    os.replace(name,target)
finally:
    if os.path.exists(name):os.unlink(name)
print(f'Compiled {sum(counts.values())} ranges ({counts[4]} IPv4, {counts[6]} IPv6); {target.stat().st_size} bytes.')
