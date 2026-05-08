import re
import os
import sys

def normalize_whitespace(text):
    return re.sub(r'\s+', '', text)

def verify_all_files():
    md_path = "XML_XSD_Contract_v2.3_Centralized 1 (11).md"
    xsd_dir = "xsd"
    
    with open(md_path, 'r', encoding='utf-8') as f:
        md_content = f.read()
    
    # Extract ALL xml code blocks from the MD
    # We look for blocks that start with <xs:schema
    all_blocks = re.findall(r'```xml\n(.*?<xs:schema.*?\n)\s*```', md_content, re.DOTALL | re.IGNORECASE)
    normalized_blocks = [normalize_whitespace(b.strip()) for b in all_blocks]
    
    local_files = sorted([f for f in os.listdir(xsd_dir) if f.endswith('.xsd')])
    
    all_good = True
    print(f"Total files to check: {len(local_files)}")
    
    for filename in local_files:
        file_path = os.path.join(xsd_dir, filename)
        with open(file_path, 'r', encoding='utf-8') as f:
            content = f.read().strip()
        
        norm_content = normalize_whitespace(content)
        
        found = False
        for i, block in enumerate(normalized_blocks):
            if norm_content == block:
                found = True
                break
        
        if not found:
            print(f"❌ ERROR: {filename} content NOT found in central contract!")
            all_good = False
        else:
            # print(f"✅ OK: {filename}")
            pass
            
    if all_good:
        print("✅ SUCCESS: Every single XSD file is a pure copy of a definition in the contract.")
        sys.exit(0)
    else:
        print("❌ FAILURE: Some files do not match the contract exactly.")
        sys.exit(1)

if __name__ == "__main__":
    verify_all_files()
