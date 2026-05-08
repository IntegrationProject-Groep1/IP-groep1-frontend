import re
import os
import sys

def normalize_whitespace(text):
    return re.sub(r'\s+', '', text)

def extract_xsds_by_section(md_path):
    with open(md_path, 'r', encoding='utf-8') as f:
        content = f.read()

    sections = re.split(r'\n(?=###\s)', content)
    
    xsds = {} # map of identifier to list of (xsd_content, header)
    
    for section in sections:
        xsd_matches = re.finditer(r'```xml\n(.*?<xs:schema.*?\n)\s*```', section, re.DOTALL | re.IGNORECASE)

        for match in xsd_matches:
            xsd_content = match.group(1).strip()
            header = section.strip().split('\n')[0]
            
            # Identifiers for this section/XSD
            ids = set()
            file_names = re.findall(r'([a-z0-9_]+)\.xsd', section, re.IGNORECASE)
            for f in file_names: ids.add(f.lower())
                
            header_words = re.findall(r'`([a-z0-9_]+)`', header, re.IGNORECASE)
            for w in header_words: ids.add(w.lower())
            
            msg_name = re.search(r'(?:[0-9.]+\s+)?([a-z0-9_]+)', header, re.IGNORECASE)
            if msg_name: ids.add(msg_name.group(1).lower())

            for i in ids:
                if i not in xsds: xsds[i] = []
                xsds[i].append((xsd_content, header))
                
    return xsds

def verify():
    md_file = "XML_XSD_Contract_v2.3_Centralized 1 (11).md"
    xsd_dir = "xsd"
    
    contract_xsds = extract_xsds_by_section(md_file)
    local_files = sorted([f for f in os.listdir(xsd_dir) if f.endswith('.xsd')])
    
    mismatches = []
    perfect = []
    not_found = []
    
    for filename in local_files:
        name = filename.replace('.xsd', '').lower()
        search_name = name
        # Mapping for special cases
        if name == 'user_created_receiver': search_name = 'user_event'
        elif name == 'user_created_sender': search_name = 'user_created'
        elif name == 'identity_create_request': search_name = 'identity_request'
        elif name == 'identity_lookup_request': search_name = 'identity_request'
        elif name == 'identity_request': search_name = 'identity_request'
        elif name == 'identity_response': search_name = 'identity_response'
        elif name == 'system_error': search_name = 'system_error'
        elif name == 'schema_log': search_name = 'system_error'
        
        file_path = os.path.join(xsd_dir, filename)
        
        with open(file_path, 'r', encoding='utf-8') as f:
            local_content = f.read().strip()
            
        if search_name in contract_xsds:
            found_functional = False
            for expected, header in contract_xsds[search_name]:
                if local_content == expected or normalize_whitespace(local_content) == normalize_whitespace(expected):
                    perfect.append(filename)
                    found_functional = True
                    break
            if not found_functional:
                mismatches.append(filename)
        else:
            not_found.append(filename)
            
    print("\n--- PERFECT/FUNCTIONAL MATCHES ---")
    for f in perfect: print(f)
    
    print("\n--- FUNCTIONAL MISMATCHES ---")
    for f in mismatches: print(f)
    
    print("\n--- NOT FOUND IN CONTRACT ---")
    for f in not_found: print(f)

if __name__ == "__main__":
    verify()
