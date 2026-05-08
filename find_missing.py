import re
import os

def normalize_name(name):
    name = name.lower()
    name = re.sub(r'[^a-z0-9_]', '_', name)
    name = re.sub(r'_+', '_', name).strip('_')
    # Remove leading numbers
    name = re.sub(r'^[0-9_]+', '', name)
    return name

def find_missing_xsds(md_path, xsd_dir):
    with open(md_path, 'r', encoding='utf-8') as f:
        content = f.read()

    local_files = [f.replace('.xsd', '').lower() for f in os.listdir(xsd_dir) if f.endswith('.xsd')]
    
    sections = re.split(r'\n(?=###\s)', content)
    
    missing = {}
    
    for section in sections:
        xsd_matches = re.finditer(r'#### XSD.*?\n\s*```xml\n(.*?)\n\s*```', section, re.DOTALL | re.IGNORECASE)
        # Also check for blocks without #### XSD but with <xs:schema
        if not re.search(r'#### XSD', section, re.IGNORECASE):
            xsd_matches = re.finditer(r'```xml\n(.*?<xs:schema.*?\n)\s*```', section, re.DOTALL | re.IGNORECASE)

        for match in xsd_matches:
            xsd_content = match.group(1).strip()
            header = section.strip().split('\n')[0]
            
            # Find possible names
            potential_names = set()
            
            # 1. Words in backticks in header
            header_words = re.findall(r'`([a-z0-9_]+)`', header, re.IGNORECASE)
            for w in header_words: potential_names.add(w.lower())
            
            # 2. Main message name from header
            msg_name = re.search(r'(?:[0-9.]+\s+)?([a-z0-9_]+)', header, re.IGNORECASE)
            if msg_name: potential_names.add(msg_name.group(1).lower())
            
            # 3. Explicit filenames in section
            file_names = re.findall(r'([a-z0-9_]+)\.xsd', section, re.IGNORECASE)
            for f in file_names: potential_names.add(f.lower())

            # Check if ANY of these names exist locally
            exists = False
            for name in potential_names:
                if name in local_files:
                    exists = True
                    break
            
            if not exists and potential_names:
                # Pick the "best" name - prefer backticks or msg_name
                best_name = list(potential_names)[0]
                if header_words: best_name = header_words[0].lower()
                elif msg_name: best_name = msg_name.group(1).lower()
                
                # Check for "request" or "response" to disambiguate
                if "request" in header.lower() and "request" not in best_name:
                    best_name += "_request"
                elif "response" in header.lower() and "response" not in best_name:
                    best_name += "_response"
                
                # Skip some generic ones
                if best_name in ['xsd', 'v2', 'v1', 'xml', 'message']: continue
                
                # Don't overwrite if we found it under a different identifier already
                if best_name not in missing:
                    missing[best_name] = xsd_content

    return missing

md_file = "XML_XSD_Contract_v2.3_Centralized 1 (11).md"
xsd_dir = "xsd"

missing = find_missing_xsds(md_file, xsd_dir)
if not missing:
    print("No missing XSDs found.")
else:
    print(f"Found {len(missing)} missing XSDs:")
    for name in sorted(missing.keys()):
        print(f" - {name}")
        # print(f"--- {name}.xsd ---")
        # print(missing[name])
